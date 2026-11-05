<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\DocumentType;
use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PaymentTerm;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Accounting\Builders\PurchaseInvoiceJournalBuilder;
use App\Services\Accounting\JournalService;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentNumberService;
use App\Services\Documents\DocumentStateMachine;
use App\Support\Accounting\InvoiceAmountAllocator;
use App\Support\Decimal;
use App\Support\Purchasing\PurchaseInvoiceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Purchase invoices billed from posted receipts, with prices from the order. Authorization lives in the routes and requests.
 *
 * Lock order (docs/ACCOUNTING.md): purchase order, receipts, order items, receipt items,
 * invoice, document sequence, journal. Every write on one order takes the order lock first.
 */
class PurchaseInvoiceService
{
    /**
     * @var list<PurchaseOrderStatus>
     */
    private const array BILLABLE_ORDER_STATUSES = [
        PurchaseOrderStatus::APPROVED,
        PurchaseOrderStatus::PARTIALLY_RECEIVED,
        PurchaseOrderStatus::RECEIVED,
        PurchaseOrderStatus::CLOSED,
    ];

    public function __construct(
        private readonly JournalService $journals = new JournalService
    ) {}

    /**
     * Posted receipt lines of an order that still have a quantity to bill, priced for that full remainder.
     *
     * @return list<array{
     *     goods_receipt_item_id: int,
     *     goods_receipt_id: int,
     *     goods_receipt_number: string,
     *     product_id: int,
     *     product_name: string,
     *     received_quantity: string,
     *     remaining_quantity: string,
     *     quantity: string,
     *     unit_price: string,
     *     discount_amount: string,
     *     tax_amount: string,
     *     line_total: string
     * }>
     */
    public function eligibleItems(PurchaseOrder $order): array
    {
        $billedByReceiptItem = $this->billedByReceiptItem($order->id);

        $lines = [];
        foreach ($this->postedReceiptItems($order->id) as $item) {
            $remaining = $this->remainingFor($item, $billedByReceiptItem);

            if (Decimal::cmp($remaining, '0') > 0) {
                $lines[] = ['item' => $item, 'quantity' => $remaining];
            }
        }

        $priced = InvoiceAmountAllocator::calculate($this->pricingInput($lines, $order->id));

        $eligible = [];
        foreach ($lines as $index => $line) {
            $item = $line['item'];
            $eligible[] = [
                'goods_receipt_item_id' => $item->id,
                'goods_receipt_id' => $item->goods_receipt_id,
                'goods_receipt_number' => $item->goodsReceipt->number,
                'product_id' => $item->product_id,
                'product_name' => $item->product->name,
                'received_quantity' => $item->quantity,
                'remaining_quantity' => $line['quantity'],
                'quantity' => $line['quantity'],
                'unit_price' => $item->purchaseOrderItem->unit_price,
                'discount_amount' => $priced['items'][$index]['discount_amount'],
                'tax_amount' => $priced['items'][$index]['tax_amount'],
                'line_total' => $priced['items'][$index]['line_total'],
            ];
        }

        return $eligible;
    }

    public function hasBillableItems(PurchaseOrder $order): bool
    {
        return self::billableOrders()->whereKey($order->id)->exists();
    }

    /**
     * Orders in a billable status with at least one posted receipt line that is not fully billed.
     *
     * @return Builder<PurchaseOrder>
     */
    public static function billableOrders(): Builder
    {
        return PurchaseOrder::query()
            ->whereIn('status', self::BILLABLE_ORDER_STATUSES)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('goods_receipt_items as gri')
                    ->join('goods_receipts as g', 'g.id', '=', 'gri.goods_receipt_id')
                    ->whereColumn('g.purchase_order_id', 'purchase_orders.id')
                    ->where('g.status', GoodsReceiptStatus::POSTED->value)
                    ->whereRaw("gri.quantity > COALESCE((
                        SELECT SUM(pii.quantity)
                        FROM purchase_invoice_items pii
                        JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
                        WHERE pii.goods_receipt_item_id = gri.id
                          AND pi.status IN ('posted', 'partially_paid', 'paid')
                    ), 0)");
            });
    }

    /**
     * Payments allocated to an invoice, read only.
     *
     * @return list<array{paymentId: int, number: string, paymentDate: string, status: string, amount: string}>
     */
    public function allocationsFor(PurchaseInvoice $invoice): array
    {
        return DB::table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.purchase_invoice_id', $invoice->id)
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
     * Create a draft invoice. Drafts reserve nothing: the remainder is checked again when posting.
     *
     * @param array{
     *     purchase_order_id: int|string,
     *     supplier_invoice_number: string,
     *     invoice_date: string,
     *     due_date?: string|null,
     *     notes?: string|null,
     *     items: list<array{goods_receipt_item_id: int|string, quantity: string|int}>
     * } $data
     *
     * @throws BusinessRuleException
     */
    public function create(array $data, ?int $userId = null): PurchaseInvoice
    {
        return DB::transaction(function () use ($data, $userId): PurchaseInvoice {
            $effectiveUserId = $userId ?? Auth::id();
            $requested = $this->normalizeRequestedLines($data['items']);
            [$order, $receiptItems] = $this->lockOrderChain((int) $data['purchase_order_id'], array_keys($requested));

            $this->assertOrderBillable($order);
            $this->assertSupplierActive($order->supplier_id);

            $supplierNumber = $this->validatedSupplierNumber($data['supplier_invoice_number'], $order->supplier_id);
            $invoiceDate = $this->parseInvoiceDate($data['invoice_date']);
            $lines = $this->buildLines($order, $receiptItems, $requested);
            $priced = PurchaseInvoiceCalculator::calculate($this->pricingInput($lines, $order->id));

            $invoice = PurchaseInvoice::create([
                'number' => DocumentNumberService::generate(DocumentType::PI, $invoiceDate),
                'supplier_id' => $order->supplier_id,
                'purchase_order_id' => $order->id,
                'supplier_invoice_number' => $supplierNumber,
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $this->resolveDueDate($order, $invoiceDate, $data['due_date'] ?? null),
                'status' => PurchaseInvoiceStatus::DRAFT,
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
     * Replace a draft's supplier number, dates, notes and lines.
     *
     * @param array{
     *     supplier_invoice_number: string,
     *     invoice_date: string,
     *     due_date?: string|null,
     *     notes?: string|null,
     *     items: list<array{goods_receipt_item_id: int|string, quantity: string|int}>
     * } $data
     *
     * @throws BusinessRuleException
     * @throws DocumentNotEditableException
     */
    public function update(PurchaseInvoice $invoice, array $data, ?int $userId = null): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $userId): PurchaseInvoice {
            $effectiveUserId = $userId ?? Auth::id();
            $requested = $this->normalizeRequestedLines($data['items']);
            [$order, $receiptItems] = $this->lockOrderChain($this->orderIdOf($invoice), array_keys($requested));
            $locked = $this->lockInvoice($invoice);

            if (! $locked->isEditable()) {
                throw new DocumentNotEditableException('Hanya draf faktur pembelian yang dapat diubah.');
            }

            $supplierNumber = $this->validatedSupplierNumber($data['supplier_invoice_number'], $order->supplier_id, $locked->id);
            $invoiceDate = $this->parseInvoiceDate($data['invoice_date']);
            $lines = $this->buildLines($order, $receiptItems, $requested);
            $priced = PurchaseInvoiceCalculator::calculate($this->pricingInput($lines, $order->id));

            $locked->fill([
                'supplier_invoice_number' => $supplierNumber,
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $this->resolveDueDate($order, $invoiceDate, $data['due_date'] ?? null),
                ...$priced['header'],
                'notes' => $this->cleanNotes($data['notes'] ?? null),
            ]);

            if ($effectiveUserId !== null) {
                $locked->updated_by = $effectiveUserId;
            }

            $locked->save();

            // @audit-bypass: purchase_invoice_items has no audit columns; the new lines are audited explicitly in writeItems
            PurchaseInvoiceItem::query()->where('purchase_invoice_id', $locked->id)->delete();

            $this->writeItems($locked, $lines, $priced['items']);

            return $locked->load('items');
        });
    }

    /**
     * Post a draft: checks the remainder again under lock, recalculates and writes journal JT-PI.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function post(PurchaseInvoice $invoice, ?int $userId = null): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $userId): PurchaseInvoice {
            $effectiveUserId = $userId ?? Auth::id();
            $orderId = $this->orderIdOf($invoice);

            // A draft's lines only change under the order lock, so they are read after taking it.
            $order = PurchaseOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $currentItems = PurchaseInvoiceItem::query()
                ->where('purchase_invoice_id', $invoice->id)
                ->orderBy('line_no')
                ->get();

            [, $receiptItems] = $this->lockOrderChain(
                $orderId,
                $currentItems->pluck('goods_receipt_item_id')->filter()->map(fn ($id): int => (int) $id)->all()
            );

            $locked = $this->lockInvoice($invoice);

            DocumentStateMachine::assertTransition($locked->status, PurchaseInvoiceStatus::POSTED);

            $this->assertOrderBillable($order);
            $this->assertSupplierActive($order->supplier_id);

            $requested = [];
            foreach ($currentItems as $item) {
                $requested[(int) $item->goods_receipt_item_id] = $item->quantity;
            }

            $lines = $this->buildLines($order, $receiptItems, $requested);
            $priced = PurchaseInvoiceCalculator::calculate($this->pricingInput($lines, $order->id));

            foreach ($currentItems->values() as $index => $item) {
                $item->fill([
                    'discount_amount' => $priced['items'][$index]['discount_amount'],
                    'tax_amount' => $priced['items'][$index]['tax_amount'],
                    'line_total' => $priced['items'][$index]['line_total'],
                ])->save();
            }

            $locked->fill($priced['header']);
            $locked->status = PurchaseInvoiceStatus::POSTED;
            $locked->posted_by = $effectiveUserId;
            $locked->posted_at = now();

            if ($effectiveUserId !== null) {
                $locked->updated_by = $effectiveUserId;
            }

            $journal = $this->journals->postAutomatic(
                PurchaseInvoiceJournalBuilder::build($locked, $priced['goods_value'], $priced['difference']),
                PurchaseInvoice::JOURNAL_SOURCE,
                $locked->id,
                CarbonImmutable::parse($locked->invoice_date)
            );

            $locked->journal_id = $journal?->id;
            $locked->save();

            return $locked->load('items');
        });
    }

    /**
     * Void a draft or an unpaid posted invoice. Takes the order lock because it depends on the other billings of the order.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function void(PurchaseInvoice $invoice, string $reason, ?int $userId = null): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $userId): PurchaseInvoice {
            $effectiveUserId = $userId ?? Auth::id();

            PurchaseOrder::query()->whereKey($this->orderIdOf($invoice))->lockForUpdate()->firstOrFail();

            $locked = $this->lockInvoice($invoice);

            if (! Decimal::isZero($locked->paid_amount)) {
                throw new BusinessRuleException('Faktur sudah dibayar. Silakan batalkan pembayaran terlebih dahulu.', 409, 'status');
            }

            DocumentStateMachine::assertTransition($locked->status, PurchaseInvoiceStatus::VOID);

            $trimmedReason = trim($reason);

            if ($trimmedReason === '') {
                throw new BusinessRuleException('Alasan pembatalan wajib diisi.', 422, 'reason');
            }

            if (mb_strlen($trimmedReason) > 255) {
                throw new BusinessRuleException('Alasan pembatalan maksimal 255 karakter.', 422, 'reason');
            }

            if ($locked->status === PurchaseInvoiceStatus::POSTED) {
                $this->assertNotCompletedByLaterInvoice($locked);
                $this->journals->reverseAutomatic(PurchaseInvoice::JOURNAL_SOURCE, $locked->id, now());
            }

            $locked->status = PurchaseInvoiceStatus::VOID;
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
     * Term days from the order, then the supplier, then zero.
     */
    public function termDays(PurchaseOrder $order): int
    {
        $termId = $order->payment_term_id
            ?? Supplier::query()->whereKey($order->supplier_id)->value('payment_term_id');

        if ($termId === null) {
            return 0;
        }

        return (int) PaymentTerm::query()->whereKey($termId)->value('days');
    }

    /**
     * The value recognized per billing is not stored, and the billing that completes a receipt line took whatever
     * was left of its value. It must therefore be voided first, or goods received not invoiced would stop clearing exactly.
     *
     * @throws BusinessRuleException
     */
    private function assertNotCompletedByLaterInvoice(PurchaseInvoice $invoice): void
    {
        $receiptItemIds = PurchaseInvoiceItem::query()
            ->where('purchase_invoice_id', $invoice->id)
            ->pluck('goods_receipt_item_id')
            ->filter();

        foreach (GoodsReceiptItem::query()->whereIn('id', $receiptItemIds)->get() as $receiptItem) {
            $billings = PurchaseInvoiceItem::query()
                ->join('purchase_invoices as pi', 'pi.id', '=', 'purchase_invoice_items.purchase_invoice_id')
                ->where('purchase_invoice_items.goods_receipt_item_id', $receiptItem->id)
                ->whereIn('pi.status', PurchaseInvoice::BILLED_STATUSES)
                ->orderByDesc('pi.journal_id')
                ->orderByDesc('pi.id')
                ->select(['purchase_invoice_items.quantity', 'pi.id as invoice_id', 'pi.number as invoice_number'])
                ->toBase()
                ->get();

            $billed = $billings->reduce(fn (string $sum, $row): string => Decimal::add($sum, (string) $row->quantity, Decimal::SCALE_QUANTITY), '0.0000');
            $last = $billings->first();

            if ($last !== null && Decimal::cmp($billed, $receiptItem->quantity) === 0 && (int) $last->invoice_id !== $invoice->id) {
                throw new BusinessRuleException(
                    "Penagihan barang ini sudah digenapi faktur {$last->invoice_number}. Silakan batalkan faktur tersebut terlebih dahulu.",
                    409,
                    'status'
                );
            }
        }
    }

    /**
     * @param  list<int>  $receiptItemIds
     * @return array{PurchaseOrder, Collection<int, GoodsReceiptItem>}
     */
    private function lockOrderChain(int $orderId, array $receiptItemIds): array
    {
        $order = PurchaseOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

        $receiptIds = GoodsReceiptItem::query()
            ->whereIn('id', $receiptItemIds)
            ->pluck('goods_receipt_id');

        GoodsReceipt::query()->whereIn('id', $receiptIds)->orderBy('id')->lockForUpdate()->get();
        PurchaseOrderItem::query()->where('purchase_order_id', $order->id)->orderBy('id')->lockForUpdate()->get();

        $receiptItems = GoodsReceiptItem::query()
            ->with(['goodsReceipt', 'product', 'purchaseOrderItem.taxRate'])
            ->whereIn('id', $receiptItemIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return [$order, $receiptItems];
    }

    private function lockInvoice(PurchaseInvoice $invoice): PurchaseInvoice
    {
        return PurchaseInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
    }

    private function orderIdOf(PurchaseInvoice $invoice): int
    {
        if ($invoice->purchase_order_id === null) {
            throw new BusinessRuleException('Faktur ini tidak terhubung dengan pesanan pembelian.', 422, 'purchase_order_id');
        }

        return (int) $invoice->purchase_order_id;
    }

    /**
     * @throws BusinessRuleException
     */
    private function validatedSupplierNumber(string $number, int $supplierId, ?int $exceptInvoiceId = null): string
    {
        $trimmed = trim($number);

        if ($trimmed === '') {
            throw new BusinessRuleException('Nomor faktur pemasok wajib diisi.', 422, 'supplier_invoice_number');
        }

        if (mb_strlen($trimmed) > 50) {
            throw new BusinessRuleException('Nomor faktur pemasok maksimal 50 karakter.', 422, 'supplier_invoice_number');
        }

        $taken = PurchaseInvoice::query()
            ->where('supplier_id', $supplierId)
            ->where('supplier_invoice_number', $trimmed)
            ->when($exceptInvoiceId !== null, fn (Builder $query): Builder => $query->whereKeyNot($exceptInvoiceId))
            ->exists();

        if ($taken) {
            throw new BusinessRuleException('Nomor faktur pemasok sudah dipakai untuk pemasok ini, termasuk oleh faktur yang sudah dibatalkan.', 422, 'supplier_invoice_number');
        }

        return $trimmed;
    }

    /**
     * @param  iterable<int, array{goods_receipt_item_id: int|string, quantity: string|int}>  $items
     * @return array<int, string> quantity per goods receipt item, in request order
     *
     * @throws BusinessRuleException
     */
    private function normalizeRequestedLines(iterable $items): array
    {
        $requested = [];

        foreach ($items as $item) {
            $id = (int) $item['goods_receipt_item_id'];

            if (isset($requested[$id])) {
                throw new BusinessRuleException('Satu baris penerimaan barang tidak boleh ditagih dua kali pada faktur yang sama.', 422, 'items');
            }

            $requested[$id] = Decimal::round((string) $item['quantity'], Decimal::SCALE_QUANTITY);
        }

        if ($requested === []) {
            throw new BusinessRuleException('Faktur harus memiliki minimal 1 baris barang.', 422, 'items');
        }

        return $requested;
    }

    /**
     * @param  Collection<int, GoodsReceiptItem>  $receiptItems
     * @param  array<int, string>  $requested
     * @return list<array{item: GoodsReceiptItem, quantity: string}>
     *
     * @throws BusinessRuleException
     */
    private function buildLines(PurchaseOrder $order, Collection $receiptItems, array $requested): array
    {
        $billedByReceiptItem = $this->billedByReceiptItem($order->id);
        $lines = [];
        $lineNo = 1;

        foreach ($requested as $id => $quantity) {
            $item = $receiptItems->get($id);

            if ($item === null || $item->goodsReceipt->purchase_order_id !== $order->id) {
                throw new BusinessRuleException("Baris ke-{$lineNo} bukan bagian dari penerimaan barang pesanan pembelian ini.", 422, 'items');
            }

            if ($item->goodsReceipt->status !== GoodsReceiptStatus::POSTED) {
                throw new BusinessRuleException("Penerimaan barang {$item->goodsReceipt->number} belum diposting atau sudah dibatalkan sehingga tidak dapat ditagih.", 422, 'items');
            }

            if (Decimal::cmp($quantity, '0') <= 0) {
                throw new BusinessRuleException("Kuantitas baris ke-{$lineNo} harus lebih besar dari 0.", 422, 'items');
            }

            $remaining = $this->remainingFor($item, $billedByReceiptItem);

            if (Decimal::cmp($quantity, $remaining) > 0) {
                $quantityText = Decimal::trim($quantity);
                $remainingText = Decimal::trim($remaining);

                throw new BusinessRuleException("Kuantitas baris ke-{$lineNo} ({$quantityText}) melebihi sisa yang dapat ditagih ({$remainingText}) pada penerimaan barang {$item->goodsReceipt->number}.", 422, 'items');
            }

            $lines[] = ['item' => $item, 'quantity' => $quantity];
            $lineNo++;
        }

        return $lines;
    }

    /**
     * @param  list<array{item: GoodsReceiptItem, quantity: string}>  $lines
     * @return list<array{
     *     order_item_key: int,
     *     receipt_item_key: int,
     *     quantity: string,
     *     unit_price: string,
     *     order_quantity: string,
     *     order_discount: string,
     *     tax_rate_percent: string,
     *     billed_quantity: string,
     *     billed_discount: string,
     *     billed_lines: int,
     *     receipt_quantity: string,
     *     unit_cost: string,
     *     recognized_quantity: string,
     *     recognized_value: string
     * }>
     */
    private function pricingInput(array $lines, int $orderId): array
    {
        $billed = $this->billedByOrderItem($orderId);
        $recognized = $this->recognizedByReceiptItem($orderId);
        $input = [];

        foreach ($lines as $line) {
            $item = $line['item'];
            $orderItem = $item->purchaseOrderItem;

            $input[] = [
                'order_item_key' => $orderItem->id,
                'receipt_item_key' => $item->id,
                'quantity' => $line['quantity'],
                'unit_price' => $orderItem->unit_price,
                'order_quantity' => $orderItem->quantity,
                'order_discount' => $orderItem->discount_amount,
                'tax_rate_percent' => $orderItem->taxRate === null ? '0.00' : (string) $orderItem->taxRate->rate,
                'billed_quantity' => $billed[$orderItem->id]['quantity'] ?? '0.0000',
                'billed_discount' => $billed[$orderItem->id]['discount'] ?? '0.00',
                'billed_lines' => $billed[$orderItem->id]['lines'] ?? 0,
                'receipt_quantity' => $item->quantity,
                'unit_cost' => $item->unit_cost,
                'recognized_quantity' => $recognized[$item->id]['quantity'] ?? '0.0000',
                'recognized_value' => $recognized[$item->id]['value'] ?? '0.00',
            ];
        }

        return $input;
    }

    /**
     * @param  list<array{item: GoodsReceiptItem, quantity: string}>  $lines
     * @param  list<array<string, string>>  $amounts
     */
    private function writeItems(PurchaseInvoice $invoice, array $lines, array $amounts): void
    {
        foreach ($lines as $index => $line) {
            $item = $line['item'];
            $orderItem = $item->purchaseOrderItem;

            PurchaseInvoiceItem::create([
                'purchase_invoice_id' => $invoice->id,
                'line_no' => $index + 1,
                'product_id' => $item->product_id,
                'goods_receipt_item_id' => $item->id,
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
            event: 'purchase_invoice.items_saved',
            auditableType: PurchaseInvoice::class,
            auditableId: $invoice->id,
            new: ['items' => array_map(fn (array $line): array => [
                'goods_receipt_item_id' => $line['item']->id,
                'quantity' => $line['quantity'],
            ], $lines)],
            strict: true
        );
    }

    /**
     * @return Collection<int, GoodsReceiptItem>
     */
    private function postedReceiptItems(int $orderId): Collection
    {
        return GoodsReceiptItem::query()
            ->with(['goodsReceipt:id,number,purchase_order_id,status', 'product:id,name', 'purchaseOrderItem.taxRate'])
            ->whereHas('goodsReceipt', function (Builder $query) use ($orderId): void {
                $query->where('purchase_order_id', $orderId)->where('status', GoodsReceiptStatus::POSTED);
            })
            ->orderBy('goods_receipt_id')
            ->orderBy('line_no')
            ->get();
    }

    /**
     * @param  array<int, string>  $billedByReceiptItem
     */
    private function remainingFor(GoodsReceiptItem $item, array $billedByReceiptItem): string
    {
        return Decimal::sub($item->quantity, $billedByReceiptItem[$item->id] ?? '0.0000', Decimal::SCALE_QUANTITY);
    }

    /**
     * Quantity already billed per receipt line, on invoices that are not drafts or void.
     *
     * @return array<int, string>
     */
    private function billedByReceiptItem(int $orderId): array
    {
        return $this->billedLines($orderId)
            ->groupBy('pii.goods_receipt_item_id')
            ->selectRaw('pii.goods_receipt_item_id as id, SUM(pii.quantity) as quantity')
            ->pluck('quantity', 'id')
            ->map(fn ($quantity): string => (string) $quantity)
            ->all();
    }

    /**
     * Quantity, discount and number of billings per order line, on invoices that are not drafts or void.
     *
     * @return array<int, array{quantity: string, discount: string, lines: int}>
     */
    private function billedByOrderItem(int $orderId): array
    {
        return $this->billedLines($orderId)
            ->join('goods_receipt_items as gri', 'gri.id', '=', 'pii.goods_receipt_item_id')
            ->groupBy('gri.purchase_order_item_id')
            ->selectRaw('gri.purchase_order_item_id as id, SUM(pii.quantity) as quantity, SUM(pii.discount_amount) as discount, COUNT(*) as line_count')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->id => [
                'quantity' => (string) $row->quantity,
                'discount' => (string) $row->discount,
                'lines' => (int) $row->line_count,
            ]])
            ->all();
    }

    /**
     * Receipt value recognized so far per receipt line. Earlier billings never completed the line, so each
     * recognized exactly V(quantity x unit_cost), which assertNotCompletedByLaterInvoice keeps true after voids.
     *
     * @return array<int, array{quantity: string, value: string}>
     */
    private function recognizedByReceiptItem(int $orderId): array
    {
        $rows = $this->billedLines($orderId)
            ->join('goods_receipt_items as gri', 'gri.id', '=', 'pii.goods_receipt_item_id')
            ->select(['pii.goods_receipt_item_id as id', 'pii.quantity', 'gri.unit_cost'])
            ->get();

        $recognized = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $recognized[$id] = [
                'quantity' => Decimal::add($recognized[$id]['quantity'] ?? '0.0000', (string) $row->quantity, Decimal::SCALE_QUANTITY),
                'value' => Decimal::add($recognized[$id]['value'] ?? '0.00', Decimal::mul((string) $row->quantity, (string) $row->unit_cost)),
            ];
        }

        return $recognized;
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function billedLines(int $orderId)
    {
        return DB::table('purchase_invoice_items as pii')
            ->join('purchase_invoices as pi', 'pi.id', '=', 'pii.purchase_invoice_id')
            ->where('pi.purchase_order_id', $orderId)
            ->whereIn('pi.status', PurchaseInvoice::BILLED_STATUSES);
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertOrderBillable(PurchaseOrder $order): void
    {
        if (! in_array($order->status, self::BILLABLE_ORDER_STATUSES, true)) {
            throw new BusinessRuleException('Faktur hanya dapat dibuat untuk pesanan pembelian yang sudah disetujui dan belum dibatalkan.', 422, 'purchase_order_id');
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertSupplierActive(int $supplierId): void
    {
        $active = Supplier::query()->whereKey($supplierId)->where('is_active', true)->exists();

        if (! $active) {
            throw new BusinessRuleException('Pemasok tidak aktif atau tidak ditemukan.', 422, 'supplier_id');
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
    private function resolveDueDate(PurchaseOrder $order, CarbonImmutable $invoiceDate, ?string $dueDate): string
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

    private function cleanNotes(?string $notes): ?string
    {
        $trimmed = trim((string) $notes);

        return $trimmed === '' ? null : $trimmed;
    }
}
