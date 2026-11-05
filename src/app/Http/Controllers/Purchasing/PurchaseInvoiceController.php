<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\PurchaseInvoiceStoreRequest;
use App\Http\Requests\Purchasing\PurchaseInvoiceUpdateRequest;
use App\Http\Requests\Purchasing\PurchaseInvoiceVoidRequest;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Accounting\JournalSourceResolver;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Support\Decimal;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PurchaseInvoiceController extends Controller
{
    private const int BILLABLE_ORDER_LIMIT = 50;

    public function __construct(
        private readonly PurchaseInvoiceService $invoiceService
    ) {}

    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(PurchaseInvoice::query()->with(['supplier:id,code,name', 'purchaseOrder:id,number']))
            ->searchable(['number', 'supplier_invoice_number'])
            ->sortable(['id', 'number', 'invoice_date', 'due_date', 'status', 'total', 'created_at'])
            ->filterable([
                'status' => 'status',
                'supplier_id' => 'supplier_id',
                'date_from' => function (Builder $query, mixed $value): void {
                    $query->where('invoice_date', '>=', $value);
                },
                'date_to' => function (Builder $query, mixed $value): void {
                    $query->where('invoice_date', '<=', $value);
                },
                'overdue' => function (Builder $query, mixed $value): void {
                    if ((string) $value === '1') {
                        $query->whereIn('status', [PurchaseInvoiceStatus::POSTED, PurchaseInvoiceStatus::PARTIALLY_PAID])
                            ->where('due_date', '<', now()->toDateString())
                            ->whereColumn('total', '>', 'paid_amount');
                    }
                },
            ])
            ->defaultSort('invoice_date', 'desc')
            ->paginate($request, 25);

        return Inertia::render('Purchasing/Invoices/Index', [
            'items' => Paginated::from($paginator, fn (PurchaseInvoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'supplierInvoiceNumber' => $invoice->supplier_invoice_number,
                'invoiceDate' => $invoice->invoice_date->toDateString(),
                'dueDate' => $invoice->due_date->toDateString(),
                'supplier' => [
                    'id' => $invoice->supplier->id,
                    'code' => $invoice->supplier->code,
                    'name' => $invoice->supplier->name,
                ],
                'purchaseOrderNumber' => $invoice->purchaseOrder?->number,
                'total' => $invoice->total,
                'paidAmount' => $invoice->paid_amount,
                'outstanding' => $invoice->outstanding(),
                'status' => $invoice->status->value,
                'isOverdue' => $this->isOverdue($invoice),
            ], $request),
            'suppliers' => Supplier::query()
                ->select(['id', 'code', 'name'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'statuses' => array_map(fn (PurchaseInvoiceStatus $status): string => $status->value, PurchaseInvoiceStatus::cases()),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $orderId = $request->query('purchase_order_id');

        if ($orderId === null || $orderId === '') {
            return Inertia::render('Purchasing/Invoices/Create', [
                'purchaseOrder' => null,
                'eligibleItems' => [],
                'defaults' => null,
                'billableOrders' => $this->billableOrders($request),
                'filters' => ['search' => (string) $request->query('search', '')],
            ]);
        }

        $order = is_numeric($orderId) ? PurchaseInvoiceService::billableOrders()->find((int) $orderId) : null;

        if ($order === null) {
            return redirect()
                ->route('purchasing.invoices.create')
                ->with('error', 'Pesanan pembelian tidak ditemukan atau tidak memiliki sisa yang dapat ditagih.');
        }

        $today = now()->toImmutable();

        return Inertia::render('Purchasing/Invoices/Create', [
            'purchaseOrder' => $this->orderProps($order),
            'eligibleItems' => array_map($this->eligibleProps(...), $this->invoiceService->eligibleItems($order)),
            'defaults' => [
                'invoiceDate' => $today->toDateString(),
                'dueDate' => $today->addDays($this->invoiceService->termDays($order))->toDateString(),
            ],
            'billableOrders' => [],
            'filters' => ['search' => ''],
        ]);
    }

    public function store(PurchaseInvoiceStoreRequest $request): RedirectResponse
    {
        $invoice = $this->invoiceService->create($request->validated());

        return redirect()
            ->route('purchasing.invoices.show', $invoice)
            ->with('success', 'Faktur pembelian berhasil dibuat.');
    }

    public function show(PurchaseInvoice $purchaseInvoice): Response
    {
        return Inertia::render('Purchasing/Invoices/Show', [
            'item' => $this->detailProps($purchaseInvoice),
            'journal' => JournalSourceResolver::related(PurchaseInvoice::JOURNAL_SOURCE, $purchaseInvoice->id),
            'allocations' => $this->invoiceService->allocationsFor($purchaseInvoice),
        ]);
    }

    public function print(PurchaseInvoice $purchaseInvoice): Response
    {
        return Inertia::render('Purchasing/Invoices/Print', [
            'item' => $this->detailProps($purchaseInvoice),
        ]);
    }

    public function edit(PurchaseInvoice $purchaseInvoice): Response|RedirectResponse
    {
        if (! $purchaseInvoice->isEditable() || $purchaseInvoice->purchase_order_id === null) {
            return redirect()
                ->route('purchasing.invoices.show', $purchaseInvoice)
                ->with('error', 'Hanya draf faktur pembelian yang dapat diubah.');
        }

        $purchaseInvoice->load('items');

        $order = PurchaseOrder::query()->findOrFail($purchaseInvoice->purchase_order_id);
        $saved = $purchaseInvoice->items->pluck('quantity', 'goods_receipt_item_id')->all();

        $eligible = array_map(function (array $line) use ($saved): array {
            $line['quantity'] = (string) ($saved[$line['goods_receipt_item_id']] ?? '0.0000');

            return $this->eligibleProps($line);
        }, $this->invoiceService->eligibleItems($order));

        return Inertia::render('Purchasing/Invoices/Edit', [
            'item' => [
                'id' => $purchaseInvoice->id,
                'number' => $purchaseInvoice->number,
                'supplierInvoiceNumber' => $purchaseInvoice->supplier_invoice_number,
                'invoiceDate' => $purchaseInvoice->invoice_date->toDateString(),
                'dueDate' => $purchaseInvoice->due_date->toDateString(),
                'notes' => $purchaseInvoice->notes,
            ],
            'purchaseOrder' => $this->orderProps($order),
            'eligibleItems' => $eligible,
        ]);
    }

    public function update(PurchaseInvoiceUpdateRequest $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $updated = $this->invoiceService->update($purchaseInvoice, $request->validated());

        return redirect()
            ->route('purchasing.invoices.show', $updated)
            ->with('success', 'Faktur pembelian berhasil diperbarui.');
    }

    public function post(PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $this->invoiceService->post($purchaseInvoice);

        return back()->with('success', 'Faktur pembelian berhasil diposting dan jurnal telah dibuat.');
    }

    public function void(PurchaseInvoiceVoidRequest $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $this->invoiceService->void($purchaseInvoice, (string) $request->input('reason'));

        return back()->with('success', 'Faktur pembelian berhasil dibatalkan.');
    }

    /**
     * @return list<array{id: int, number: string, orderDate: string, status: string, total: string, supplier: array{id: int, code: string, name: string}}>
     */
    private function billableOrders(Request $request): array
    {
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $query = PurchaseInvoiceService::billableOrders()->with('supplier:id,code,name');

        if ($search !== '') {
            $escaped = ListQuery::escapeLike($search);

            $query->where(function (Builder $inner) use ($escaped): void {
                $inner->where('number', 'LIKE', "%{$escaped}%")
                    ->orWhereHas('supplier', function (Builder $supplier) use ($escaped): void {
                        $supplier->where('name', 'LIKE', "%{$escaped}%");
                    });
            });
        }

        return $query
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(self::BILLABLE_ORDER_LIMIT)
            ->get()
            ->map(fn (PurchaseOrder $order): array => [
                'id' => $order->id,
                'number' => $order->number,
                'orderDate' => $order->order_date->toDateString(),
                'status' => $order->status->value,
                'total' => $order->total,
                'supplier' => [
                    'id' => $order->supplier->id,
                    'code' => $order->supplier->code,
                    'name' => $order->supplier->name,
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function orderProps(PurchaseOrder $order): array
    {
        $order->loadMissing('supplier:id,code,name');

        return [
            'id' => $order->id,
            'number' => $order->number,
            'orderDate' => $order->order_date->toDateString(),
            'supplier' => [
                'id' => $order->supplier->id,
                'code' => $order->supplier->code,
                'name' => $order->supplier->name,
            ],
            'termDays' => $this->invoiceService->termDays($order),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function eligibleProps(array $line): array
    {
        return [
            'goodsReceiptItemId' => $line['goods_receipt_item_id'],
            'goodsReceiptId' => $line['goods_receipt_id'],
            'goodsReceiptNumber' => $line['goods_receipt_number'],
            'productId' => $line['product_id'],
            'productName' => $line['product_name'],
            'receivedQuantity' => $line['received_quantity'],
            'remainingQuantity' => $line['remaining_quantity'],
            'quantity' => $line['quantity'],
            'unitPrice' => $line['unit_price'],
            'discountAmount' => $line['discount_amount'],
            'taxAmount' => $line['tax_amount'],
            'lineTotal' => $line['line_total'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailProps(PurchaseInvoice $invoice): array
    {
        $invoice->load([
            'supplier:id,code,name,address',
            'purchaseOrder:id,number',
            'poster:id,name',
            'voider:id,name',
            'creator:id,name',
            'items.taxRate:id,code,rate',
            'items.goodsReceiptItem.goodsReceipt:id,number',
        ]);

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'supplierInvoiceNumber' => $invoice->supplier_invoice_number,
            'status' => $invoice->status->value,
            'invoiceDate' => $invoice->invoice_date->toDateString(),
            'dueDate' => $invoice->due_date->toDateString(),
            'isOverdue' => $this->isOverdue($invoice),
            'subtotal' => $invoice->subtotal,
            'discountAmount' => $invoice->discount_amount,
            'taxAmount' => $invoice->tax_amount,
            'total' => $invoice->total,
            'paidAmount' => $invoice->paid_amount,
            'outstanding' => $invoice->outstanding(),
            'notes' => $invoice->notes,
            'supplier' => [
                'id' => $invoice->supplier->id,
                'code' => $invoice->supplier->code,
                'name' => $invoice->supplier->name,
                'address' => $invoice->supplier->address,
            ],
            'purchaseOrder' => $invoice->purchaseOrder === null ? null : [
                'id' => $invoice->purchaseOrder->id,
                'number' => $invoice->purchaseOrder->number,
            ],
            'creator' => $invoice->creator === null ? null : ['id' => $invoice->creator->id, 'name' => $invoice->creator->name],
            'poster' => $invoice->poster === null ? null : ['id' => $invoice->poster->id, 'name' => $invoice->poster->name],
            'voider' => $invoice->voider === null ? null : ['id' => $invoice->voider->id, 'name' => $invoice->voider->name],
            'postedAt' => $invoice->posted_at?->toIso8601String(),
            'voidedAt' => $invoice->voided_at?->toIso8601String(),
            'voidReason' => $invoice->void_reason,
            'items' => $invoice->items->map(fn (PurchaseInvoiceItem $item): array => [
                'id' => $item->id,
                'lineNo' => $item->line_no,
                'description' => $item->description,
                'goodsReceiptId' => $item->goodsReceiptItem?->goods_receipt_id,
                'goodsReceiptNumber' => $item->goodsReceiptItem?->goodsReceipt->number,
                'quantity' => $item->quantity,
                'unitPrice' => $item->unit_price,
                'discountAmount' => $item->discount_amount,
                'taxRate' => $item->taxRate === null ? null : [
                    'code' => $item->taxRate->code,
                    'rate' => (string) $item->taxRate->rate,
                ],
                'taxAmount' => $item->tax_amount,
                'lineTotal' => $item->line_total,
            ])->values()->all(),
        ];
    }

    private function isOverdue(PurchaseInvoice $invoice): bool
    {
        return in_array($invoice->status, [PurchaseInvoiceStatus::POSTED, PurchaseInvoiceStatus::PARTIALLY_PAID], true)
            && $invoice->due_date->toDateString() < now()->toDateString()
            && Decimal::cmp($invoice->total, $invoice->paid_amount) > 0;
    }
}
