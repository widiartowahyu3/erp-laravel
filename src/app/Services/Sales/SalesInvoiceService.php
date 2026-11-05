<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\DeliveryOrderStatus;
use App\Enums\DocumentType;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderItem;
use App\Models\PaymentTerm;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Services\Accounting\Builders\SalesInvoiceJournalBuilder;
use App\Services\Accounting\JournalService;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentNumberService;
use App\Services\Documents\DocumentStateMachine;
use App\Support\Decimal;
use App\Support\Sales\SalesInvoiceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sales invoices billed from posted deliveries. Authorization lives in the routes and requests.
 *
 * Lock order (docs/ACCOUNTING.md): sales order, delivery orders, order items, delivery items,
 * invoice, document sequence, journal. Every write on one order takes the order lock first,
 * so concurrent invoices and delivery voids on that order run one after another.
 */
class SalesInvoiceService
{
    /**
     * @var list<SalesOrderStatus>
     */
    private const array BILLABLE_ORDER_STATUSES = [
        SalesOrderStatus::APPROVED,
        SalesOrderStatus::PARTIALLY_DELIVERED,
        SalesOrderStatus::DELIVERED,
        SalesOrderStatus::CLOSED,
    ];

    public function __construct(
        private readonly JournalService $journals = new JournalService
    ) {}

    /**
     * Posted delivery lines of an order that still have a quantity to bill, priced for that full remainder.
     *
     * @return list<array{
     *     delivery_order_item_id: int,
     *     delivery_order_id: int,
     *     delivery_order_number: string,
     *     product_id: int,
     *     product_name: string,
     *     delivered_quantity: string,
     *     remaining_quantity: string,
     *     quantity: string,
     *     unit_price: string,
     *     discount_amount: string,
     *     tax_amount: string,
     *     line_total: string
     * }>
     */
    public function eligibleItems(SalesOrder $order): array
    {
        $billedByDeliveryItem = $this->billedByDeliveryItem($order->id);

        $lines = [];
        foreach ($this->postedDeliveryItems($order->id) as $item) {
            $remaining = $this->remainingFor($item, $billedByDeliveryItem);

            if (Decimal::cmp($remaining, '0') > 0) {
                $lines[] = ['item' => $item, 'quantity' => $remaining];
            }
        }

        $priced = $this->price($lines, $order->id);

        $eligible = [];
        foreach ($lines as $index => $line) {
            $item = $line['item'];
            $eligible[] = [
                'delivery_order_item_id' => $item->id,
                'delivery_order_id' => $item->delivery_order_id,
                'delivery_order_number' => $item->deliveryOrder->number,
                'product_id' => $item->product_id,
                'product_name' => $item->product->name,
                'delivered_quantity' => $item->quantity,
                'remaining_quantity' => $line['quantity'],
                'quantity' => $line['quantity'],
                'unit_price' => $item->salesOrderItem->unit_price,
                'discount_amount' => $priced['items'][$index]['discount_amount'],
                'tax_amount' => $priced['items'][$index]['tax_amount'],
                'line_total' => $priced['items'][$index]['line_total'],
            ];
        }

        return $eligible;
    }

    public function hasBillableItems(SalesOrder $order): bool
    {
        return self::billableOrders()->whereKey($order->id)->exists();
    }

    /**
     * Payments allocated to an invoice, read only. Payments themselves arrive in Fase 10.
     *
     * @return list<array{paymentId: int, number: string, paymentDate: string, status: string, amount: string}>
     */
    public function allocationsFor(SalesInvoice $invoice): array
    {
        return DB::table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.sales_invoice_id', $invoice->id)
            ->orderBy('p.payment_date')
            ->orderBy('p.id')
            ->select(['p.id', 'p.number', 'p.payment_date', 'p.status', 'a.amount'])
            ->get()
            ->map(fn ($row): array => [
                'paymentId' => (int) $row->id,
                'number' => (string) $row->number,
                'paymentDate' => (string) $row->payment_date,
                'status' => (string) $row->status,
                'amount' => (string) $row->amount,
            ])
            ->values()
            ->all();
    }

    /**
     * Orders in a billable status with at least one posted delivery line that is not fully billed.
     *
     * @return Builder<SalesOrder>
     */
    public static function billableOrders(): Builder
    {
        return SalesOrder::query()
            ->whereIn('status', self::BILLABLE_ORDER_STATUSES)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('delivery_order_items as doi')
                    ->join('delivery_orders as d', 'd.id', '=', 'doi.delivery_order_id')
                    ->whereColumn('d.sales_order_id', 'sales_orders.id')
                    ->where('d.status', DeliveryOrderStatus::POSTED->value)
                    ->whereRaw("doi.quantity > COALESCE((
                        SELECT SUM(sii.quantity)
                        FROM sales_invoice_items sii
                        JOIN sales_invoices si ON si.id = sii.sales_invoice_id
                        WHERE sii.delivery_order_item_id = doi.id
                          AND si.status IN ('posted', 'partially_paid', 'paid')
                    ), 0)");
            });
    }

    /**
     * Create a draft invoice. Drafts reserve nothing: the remainder is checked again when posting.
     *
     * @param array{
     *     sales_order_id: int|string,
     *     invoice_date: string,
     *     due_date?: string|null,
     *     notes?: string|null,
     *     items: list<array{delivery_order_item_id: int|string, quantity: string|int}>
     * } $data
     *
     * @throws BusinessRuleException
     */
    public function create(array $data, ?int $userId = null): SalesInvoice
    {
        return DB::transaction(function () use ($data, $userId): SalesInvoice {
            $effectiveUserId = $userId ?? Auth::id();
            $requested = $this->normalizeRequestedLines($data['items']);
            [$order, $deliveryItems] = $this->lockOrderChain((int) $data['sales_order_id'], array_keys($requested));

            $this->assertOrderBillable($order);
            $this->assertCustomerActive($order->customer_id);

            $invoiceDate = $this->parseInvoiceDate($data['invoice_date']);
            $lines = $this->buildLines($order, $deliveryItems, $requested);
            $priced = $this->price($lines, $order->id);

            $invoice = SalesInvoice::create([
                'number' => DocumentNumberService::generate(DocumentType::SI, $invoiceDate),
                'customer_id' => $order->customer_id,
                'sales_order_id' => $order->id,
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $this->resolveDueDate($order, $invoiceDate, $data['due_date'] ?? null),
                'status' => SalesInvoiceStatus::DRAFT,
                ...$priced['header'],
                'paid_amount' => '0.00',
                'notes' => $this->cleanNotes($data['notes'] ?? null),
                'created_by' => $effectiveUserId,
                'updated_by' => $effectiveUserId,
            ]);

            $this->writeItems($invoice, $lines, $priced['items']);

            return $invoice->load('items');
        });
    }

    /**
     * Replace a draft's dates, notes and lines.
     *
     * @param array{
     *     invoice_date: string,
     *     due_date?: string|null,
     *     notes?: string|null,
     *     items: list<array{delivery_order_item_id: int|string, quantity: string|int}>
     * } $data
     *
     * @throws BusinessRuleException
     * @throws DocumentNotEditableException
     */
    public function update(SalesInvoice $invoice, array $data, ?int $userId = null): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $userId): SalesInvoice {
            $effectiveUserId = $userId ?? Auth::id();
            $requested = $this->normalizeRequestedLines($data['items']);
            [$order, $deliveryItems] = $this->lockOrderChain($this->orderIdOf($invoice), array_keys($requested));
            $locked = $this->lockInvoice($invoice);

            if (! $locked->isEditable()) {
                throw new DocumentNotEditableException('Hanya draf faktur penjualan yang dapat diubah.');
            }

            $invoiceDate = $this->parseInvoiceDate($data['invoice_date']);
            $lines = $this->buildLines($order, $deliveryItems, $requested);
            $priced = $this->price($lines, $order->id);

            $locked->fill([
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $this->resolveDueDate($order, $invoiceDate, $data['due_date'] ?? null),
                ...$priced['header'],
                'notes' => $this->cleanNotes($data['notes'] ?? null),
            ]);

            if ($effectiveUserId !== null) {
                $locked->updated_by = $effectiveUserId;
            }

            $locked->save();

            // @audit-bypass: sales_invoice_items has no audit columns; the new lines are audited explicitly in writeItems
            SalesInvoiceItem::query()->where('sales_invoice_id', $locked->id)->delete();

            $this->writeItems($locked, $lines, $priced['items']);

            return $locked->load('items');
        });
    }

    /**
     * Post a draft: checks the remainder again under lock, recalculates and writes journal JT-SI.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function post(SalesInvoice $invoice, ?int $userId = null): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $userId): SalesInvoice {
            $effectiveUserId = $userId ?? Auth::id();
            $orderId = $this->orderIdOf($invoice);

            // A draft's lines only change under the order lock, so they are read after taking it.
            $order = SalesOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $currentItems = SalesInvoiceItem::query()
                ->where('sales_invoice_id', $invoice->id)
                ->orderBy('line_no')
                ->get();

            [, $deliveryItems] = $this->lockOrderChain(
                $orderId,
                $currentItems->pluck('delivery_order_item_id')->filter()->map(fn ($id): int => (int) $id)->all()
            );

            $locked = $this->lockInvoice($invoice);

            DocumentStateMachine::assertTransition($locked->status, SalesInvoiceStatus::POSTED);

            $this->assertOrderBillable($order);
            $this->assertCustomerActive($order->customer_id);

            $requested = [];
            foreach ($currentItems as $item) {
                $requested[(int) $item->delivery_order_item_id] = $item->quantity;
            }

            $lines = $this->buildLines($order, $deliveryItems, $requested);
            $priced = $this->price($lines, $order->id);

            foreach ($currentItems->values() as $index => $item) {
                $item->fill([
                    'discount_amount' => $priced['items'][$index]['discount_amount'],
                    'tax_amount' => $priced['items'][$index]['tax_amount'],
                    'line_total' => $priced['items'][$index]['line_total'],
                ])->save();
            }

            $locked->fill($priced['header']);
            $locked->status = SalesInvoiceStatus::POSTED;
            $locked->posted_by = $effectiveUserId;
            $locked->posted_at = now();

            if ($effectiveUserId !== null) {
                $locked->updated_by = $effectiveUserId;
            }

            $journal = $this->journals->postAutomatic(
                SalesInvoiceJournalBuilder::build($locked),
                SalesInvoice::JOURNAL_SOURCE,
                $locked->id,
                CarbonImmutable::parse($locked->invoice_date)
            );

            $locked->journal_id = $journal?->id;
            $locked->save();

            return $locked->load('items');
        });
    }

    /**
     * Void a draft or an unpaid posted invoice. Locks the invoice only: a payment locks
     * payment then invoice, so it cannot slip past the paid amount read here.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function void(SalesInvoice $invoice, string $reason, ?int $userId = null): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $userId): SalesInvoice {
            $effectiveUserId = $userId ?? Auth::id();
            $locked = $this->lockInvoice($invoice);

            if (! Decimal::isZero($locked->paid_amount)) {
                throw new BusinessRuleException('Faktur sudah menerima pembayaran. Silakan batalkan pembayaran terlebih dahulu.', 409, 'status');
            }

            DocumentStateMachine::assertTransition($locked->status, SalesInvoiceStatus::VOID);

            $trimmedReason = trim($reason);

            if ($trimmedReason === '') {
                throw new BusinessRuleException('Alasan pembatalan wajib diisi.', 422, 'reason');
            }

            if (mb_strlen($trimmedReason) > 255) {
                throw new BusinessRuleException('Alasan pembatalan maksimal 255 karakter.', 422, 'reason');
            }

            if ($locked->status === SalesInvoiceStatus::POSTED) {
                $this->journals->reverseAutomatic(SalesInvoice::JOURNAL_SOURCE, $locked->id, now());
            }

            $locked->status = SalesInvoiceStatus::VOID;
            $locked->voided_by = $effectiveUserId;
            $locked->voided_at = now();
            $locked->void_reason = $trimmedReason;

            if ($effectiveUserId !== null) {
                $locked->updated_by = $effectiveUserId;
            }

            $locked->save();

            return $locked->load('items');
        });
    }

    /**
     * @param  list<int>  $deliveryItemIds
     * @return array{SalesOrder, Collection<int, DeliveryOrderItem>}
     */
    private function lockOrderChain(int $orderId, array $deliveryItemIds): array
    {
        $order = SalesOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

        $deliveryIds = DeliveryOrderItem::query()
            ->whereIn('id', $deliveryItemIds)
            ->pluck('delivery_order_id');

        DeliveryOrder::query()->whereIn('id', $deliveryIds)->orderBy('id')->lockForUpdate()->get();
        SalesOrderItem::query()->where('sales_order_id', $order->id)->orderBy('id')->lockForUpdate()->get();

        $deliveryItems = DeliveryOrderItem::query()
            ->with(['deliveryOrder', 'product', 'salesOrderItem.taxRate'])
            ->whereIn('id', $deliveryItemIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return [$order, $deliveryItems];
    }

    private function lockInvoice(SalesInvoice $invoice): SalesInvoice
    {
        return SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
    }

    private function orderIdOf(SalesInvoice $invoice): int
    {
        if ($invoice->sales_order_id === null) {
            throw new BusinessRuleException('Faktur ini tidak terhubung dengan pesanan penjualan.', 422, 'sales_order_id');
        }

        return (int) $invoice->sales_order_id;
    }

    /**
     * @param  iterable<int, array{delivery_order_item_id: int|string, quantity: string|int}>  $items
     * @return array<int, string> quantity per delivery order item, in request order
     *
     * @throws BusinessRuleException
     */
    private function normalizeRequestedLines(iterable $items): array
    {
        $requested = [];

        foreach ($items as $item) {
            $id = (int) $item['delivery_order_item_id'];

            if (isset($requested[$id])) {
                throw new BusinessRuleException('Satu baris surat jalan tidak boleh ditagih dua kali pada faktur yang sama.', 422, 'items');
            }

            $requested[$id] = Decimal::round((string) $item['quantity'], Decimal::SCALE_QUANTITY);
        }

        if ($requested === []) {
            throw new BusinessRuleException('Faktur harus memiliki minimal 1 baris barang.', 422, 'items');
        }

        return $requested;
    }

    /**
     * @param  Collection<int, DeliveryOrderItem>  $deliveryItems
     * @param  array<int, string>  $requested
     * @return list<array{item: DeliveryOrderItem, quantity: string}>
     *
     * @throws BusinessRuleException
     */
    private function buildLines(SalesOrder $order, Collection $deliveryItems, array $requested): array
    {
        $billedByDeliveryItem = $this->billedByDeliveryItem($order->id);
        $lines = [];
        $lineNo = 1;

        foreach ($requested as $id => $quantity) {
            $item = $deliveryItems->get($id);

            if ($item === null || $item->deliveryOrder->sales_order_id !== $order->id) {
                throw new BusinessRuleException("Baris ke-{$lineNo} bukan bagian dari surat jalan pesanan penjualan ini.", 422, 'items');
            }

            if ($item->deliveryOrder->status !== DeliveryOrderStatus::POSTED) {
                throw new BusinessRuleException("Surat jalan {$item->deliveryOrder->number} belum diposting atau sudah dibatalkan sehingga tidak dapat ditagih.", 422, 'items');
            }

            if (Decimal::cmp($quantity, '0') <= 0) {
                throw new BusinessRuleException("Kuantitas baris ke-{$lineNo} harus lebih besar dari 0.", 422, 'items');
            }

            $remaining = $this->remainingFor($item, $billedByDeliveryItem);

            if (Decimal::cmp($quantity, $remaining) > 0) {
                $quantityText = Decimal::trim($quantity);
                $remainingText = Decimal::trim($remaining);

                throw new BusinessRuleException("Kuantitas baris ke-{$lineNo} ({$quantityText}) melebihi sisa yang dapat ditagih ({$remainingText}) pada surat jalan {$item->deliveryOrder->number}.", 422, 'items');
            }

            $lines[] = ['item' => $item, 'quantity' => $quantity];
            $lineNo++;
        }

        return $lines;
    }

    /**
     * @param  list<array{item: DeliveryOrderItem, quantity: string}>  $lines
     * @return array{
     *     items: list<array{line_gross: string, discount_amount: string, line_net: string, tax_amount: string, line_total: string}>,
     *     header: array{subtotal: string, discount_amount: string, tax_amount: string, total: string}
     * }
     */
    private function price(array $lines, int $orderId): array
    {
        $billed = $this->billedByOrderItem($orderId);
        $input = [];

        foreach ($lines as $line) {
            $orderItem = $line['item']->salesOrderItem;

            $input[] = [
                'order_item_key' => $orderItem->id,
                'quantity' => $line['quantity'],
                'unit_price' => $orderItem->unit_price,
                'order_quantity' => $orderItem->quantity,
                'order_discount' => $orderItem->discount_amount,
                'tax_rate_percent' => $orderItem->taxRate === null ? '0.00' : (string) $orderItem->taxRate->rate,
                'billed_quantity' => $billed[$orderItem->id]['quantity'] ?? '0.0000',
                'billed_discount' => $billed[$orderItem->id]['discount'] ?? '0.00',
            ];
        }

        return SalesInvoiceCalculator::calculate($input);
    }

    /**
     * @param  list<array{item: DeliveryOrderItem, quantity: string}>  $lines
     * @param  list<array{line_gross: string, discount_amount: string, line_net: string, tax_amount: string, line_total: string}>  $amounts
     */
    private function writeItems(SalesInvoice $invoice, array $lines, array $amounts): void
    {
        foreach ($lines as $index => $line) {
            $item = $line['item'];
            $orderItem = $item->salesOrderItem;

            SalesInvoiceItem::create([
                'sales_invoice_id' => $invoice->id,
                'line_no' => $index + 1,
                'product_id' => $item->product_id,
                'delivery_order_item_id' => $item->id,
                'description' => mb_substr($item->product->name, 0, 255),
                'quantity' => $line['quantity'],
                'unit_price' => $orderItem->unit_price,
                'discount_amount' => $amounts[$index]['discount_amount'],
                'tax_rate_id' => $orderItem->tax_rate_id,
                'tax_amount' => $amounts[$index]['tax_amount'],
                'line_total' => $amounts[$index]['line_total'],
            ]);
        }

        AuditLogger::record(
            event: 'sales_invoice.items_saved',
            auditableType: SalesInvoice::class,
            auditableId: $invoice->id,
            new: ['items' => array_map(fn (array $line): array => [
                'delivery_order_item_id' => $line['item']->id,
                'quantity' => $line['quantity'],
            ], $lines)],
            strict: true
        );
    }

    /**
     * @return Collection<int, DeliveryOrderItem>
     */
    private function postedDeliveryItems(int $orderId): Collection
    {
        return DeliveryOrderItem::query()
            ->with(['deliveryOrder:id,number,sales_order_id,status', 'product:id,name', 'salesOrderItem.taxRate'])
            ->whereHas('deliveryOrder', function (Builder $query) use ($orderId): void {
                $query->where('sales_order_id', $orderId)->where('status', DeliveryOrderStatus::POSTED);
            })
            ->orderBy('delivery_order_id')
            ->orderBy('line_no')
            ->get();
    }

    /**
     * @param  array<int, string>  $billedByDeliveryItem
     */
    private function remainingFor(DeliveryOrderItem $item, array $billedByDeliveryItem): string
    {
        return Decimal::sub($item->quantity, $billedByDeliveryItem[$item->id] ?? '0.0000', Decimal::SCALE_QUANTITY);
    }

    /**
     * Quantity already billed per delivery line, on invoices that are not drafts or void.
     *
     * @return array<int, string>
     */
    private function billedByDeliveryItem(int $orderId): array
    {
        return $this->billedLines($orderId)
            ->groupBy('sii.delivery_order_item_id')
            ->selectRaw('sii.delivery_order_item_id as id, SUM(sii.quantity) as quantity')
            ->pluck('quantity', 'id')
            ->map(fn ($quantity): string => (string) $quantity)
            ->all();
    }

    /**
     * Quantity and discount already billed per order line, on invoices that are not drafts or void.
     *
     * @return array<int, array{quantity: string, discount: string}>
     */
    private function billedByOrderItem(int $orderId): array
    {
        return $this->billedLines($orderId)
            ->join('delivery_order_items as doi', 'doi.id', '=', 'sii.delivery_order_item_id')
            ->groupBy('doi.sales_order_item_id')
            ->selectRaw('doi.sales_order_item_id as id, SUM(sii.quantity) as quantity, SUM(sii.discount_amount) as discount')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->id => [
                'quantity' => (string) $row->quantity,
                'discount' => (string) $row->discount,
            ]])
            ->all();
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function billedLines(int $orderId)
    {
        return DB::table('sales_invoice_items as sii')
            ->join('sales_invoices as si', 'si.id', '=', 'sii.sales_invoice_id')
            ->where('si.sales_order_id', $orderId)
            ->whereIn('si.status', SalesInvoice::BILLED_STATUSES);
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertOrderBillable(SalesOrder $order): void
    {
        if (! in_array($order->status, self::BILLABLE_ORDER_STATUSES, true)) {
            throw new BusinessRuleException('Faktur hanya dapat dibuat untuk pesanan penjualan yang sudah disetujui dan belum dibatalkan.', 422, 'sales_order_id');
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertCustomerActive(int $customerId): void
    {
        $active = Customer::query()->whereKey($customerId)->where('is_active', true)->exists();

        if (! $active) {
            throw new BusinessRuleException('Pelanggan tidak aktif atau tidak ditemukan.', 422, 'customer_id');
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function parseInvoiceDate(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::parse($value)->startOfDay();

        if ($date->greaterThan(now()->startOfDay())) {
            throw new BusinessRuleException('Tanggal faktur tidak boleh di masa depan.', 422, 'invoice_date');
        }

        return $date;
    }

    /**
     * @throws BusinessRuleException
     */
    private function resolveDueDate(SalesOrder $order, CarbonImmutable $invoiceDate, ?string $dueDate): string
    {
        if ($dueDate === null || trim($dueDate) === '') {
            return $invoiceDate->addDays($this->termDays($order))->toDateString();
        }

        $parsed = CarbonImmutable::parse($dueDate)->startOfDay();

        if ($parsed->lessThan($invoiceDate)) {
            throw new BusinessRuleException('Tanggal jatuh tempo tidak boleh sebelum tanggal faktur.', 422, 'due_date');
        }

        return $parsed->toDateString();
    }

    /**
     * Term days from the order, then the customer, then zero.
     */
    public function termDays(SalesOrder $order): int
    {
        $termId = $order->payment_term_id
            ?? Customer::query()->whereKey($order->customer_id)->value('payment_term_id');

        if ($termId === null) {
            return 0;
        }

        return (int) PaymentTerm::query()->whereKey($termId)->value('days');
    }

    private function cleanNotes(?string $notes): ?string
    {
        $trimmed = trim((string) $notes);

        return $trimmed === '' ? null : $trimmed;
    }
}
