<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Enums\SalesInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesInvoiceStoreRequest;
use App\Http\Requests\Sales\SalesInvoiceUpdateRequest;
use App\Http\Requests\Sales\SalesInvoiceVoidRequest;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Services\Accounting\JournalSourceResolver;
use App\Services\Sales\SalesInvoiceService;
use App\Support\Decimal;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SalesInvoiceController extends Controller
{
    private const int BILLABLE_ORDER_LIMIT = 50;

    public function __construct(
        private readonly SalesInvoiceService $invoiceService
    ) {}

    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(SalesInvoice::query()->with(['customer:id,code,name', 'salesOrder:id,number']))
            ->searchable(['number'])
            ->sortable(['id', 'number', 'invoice_date', 'due_date', 'status', 'total', 'created_at'])
            ->filterable([
                'status' => 'status',
                'customer_id' => 'customer_id',
                'date_from' => function (Builder $query, mixed $value): void {
                    $query->where('invoice_date', '>=', $value);
                },
                'date_to' => function (Builder $query, mixed $value): void {
                    $query->where('invoice_date', '<=', $value);
                },
                'overdue' => function (Builder $query, mixed $value): void {
                    if ((string) $value === '1') {
                        $query->whereIn('status', [SalesInvoiceStatus::POSTED, SalesInvoiceStatus::PARTIALLY_PAID])
                            ->where('due_date', '<', now()->toDateString())
                            ->whereColumn('total', '>', 'paid_amount');
                    }
                },
            ])
            ->defaultSort('invoice_date', 'desc')
            ->paginate($request, 25);

        return Inertia::render('Sales/Invoices/Index', [
            'items' => Paginated::from($paginator, fn (SalesInvoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'invoiceDate' => $invoice->invoice_date->toDateString(),
                'dueDate' => $invoice->due_date->toDateString(),
                'customer' => [
                    'id' => $invoice->customer->id,
                    'code' => $invoice->customer->code,
                    'name' => $invoice->customer->name,
                ],
                'salesOrderNumber' => $invoice->salesOrder?->number,
                'total' => $invoice->total,
                'paidAmount' => $invoice->paid_amount,
                'outstanding' => $invoice->outstanding(),
                'status' => $invoice->status->value,
                'isOverdue' => $this->isOverdue($invoice),
            ], $request),
            'customers' => Customer::query()
                ->select(['id', 'code', 'name'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'statuses' => array_map(fn (SalesInvoiceStatus $status): string => $status->value, SalesInvoiceStatus::cases()),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $orderId = $request->query('sales_order_id');

        if ($orderId === null || $orderId === '') {
            return Inertia::render('Sales/Invoices/Create', [
                'salesOrder' => null,
                'eligibleItems' => [],
                'defaults' => null,
                'billableOrders' => $this->billableOrders($request),
                'filters' => ['search' => (string) $request->query('search', '')],
            ]);
        }

        $order = is_numeric($orderId) ? SalesInvoiceService::billableOrders()->find((int) $orderId) : null;

        if ($order === null) {
            return redirect()
                ->route('sales.invoices.create')
                ->with('error', 'Pesanan penjualan tidak ditemukan atau tidak memiliki sisa yang dapat ditagih.');
        }

        $today = now()->toImmutable();

        return Inertia::render('Sales/Invoices/Create', [
            'salesOrder' => $this->orderProps($order),
            'eligibleItems' => array_map($this->eligibleProps(...), $this->invoiceService->eligibleItems($order)),
            'defaults' => [
                'invoiceDate' => $today->toDateString(),
                'dueDate' => $today->addDays($this->invoiceService->termDays($order))->toDateString(),
            ],
            'billableOrders' => [],
            'filters' => ['search' => ''],
        ]);
    }

    public function store(SalesInvoiceStoreRequest $request): RedirectResponse
    {
        $invoice = $this->invoiceService->create($request->validated());

        return redirect()
            ->route('sales.invoices.show', $invoice)
            ->with('success', 'Faktur penjualan berhasil dibuat.');
    }

    public function show(SalesInvoice $salesInvoice): Response
    {
        return Inertia::render('Sales/Invoices/Show', [
            'item' => $this->detailProps($salesInvoice),
            'journal' => JournalSourceResolver::related(SalesInvoice::JOURNAL_SOURCE, $salesInvoice->id),
            'allocations' => $this->invoiceService->allocationsFor($salesInvoice),
        ]);
    }

    public function print(SalesInvoice $salesInvoice): Response
    {
        return Inertia::render('Sales/Invoices/Print', [
            'item' => $this->detailProps($salesInvoice),
        ]);
    }

    public function edit(SalesInvoice $salesInvoice): Response|RedirectResponse
    {
        if (! $salesInvoice->isEditable() || $salesInvoice->sales_order_id === null) {
            return redirect()
                ->route('sales.invoices.show', $salesInvoice)
                ->with('error', 'Hanya draf faktur penjualan yang dapat diubah.');
        }

        $salesInvoice->load('items');

        $order = SalesOrder::query()->findOrFail($salesInvoice->sales_order_id);
        $saved = $salesInvoice->items->pluck('quantity', 'delivery_order_item_id')->all();

        $eligible = array_map(function (array $line) use ($saved): array {
            $line['quantity'] = (string) ($saved[$line['delivery_order_item_id']] ?? '0.0000');

            return $this->eligibleProps($line);
        }, $this->invoiceService->eligibleItems($order));

        return Inertia::render('Sales/Invoices/Edit', [
            'item' => [
                'id' => $salesInvoice->id,
                'number' => $salesInvoice->number,
                'invoiceDate' => $salesInvoice->invoice_date->toDateString(),
                'dueDate' => $salesInvoice->due_date->toDateString(),
                'notes' => $salesInvoice->notes,
            ],
            'salesOrder' => $this->orderProps($order),
            'eligibleItems' => $eligible,
        ]);
    }

    public function update(SalesInvoiceUpdateRequest $request, SalesInvoice $salesInvoice): RedirectResponse
    {
        $updated = $this->invoiceService->update($salesInvoice, $request->validated());

        return redirect()
            ->route('sales.invoices.show', $updated)
            ->with('success', 'Faktur penjualan berhasil diperbarui.');
    }

    public function post(SalesInvoice $salesInvoice): RedirectResponse
    {
        $this->invoiceService->post($salesInvoice);

        return back()->with('success', 'Faktur penjualan berhasil diposting dan jurnal telah dibuat.');
    }

    public function void(SalesInvoiceVoidRequest $request, SalesInvoice $salesInvoice): RedirectResponse
    {
        $this->invoiceService->void($salesInvoice, (string) $request->input('reason'));

        return back()->with('success', 'Faktur penjualan berhasil dibatalkan.');
    }

    /**
     * @return list<array{id: int, number: string, orderDate: string, status: string, total: string, customer: array{id: int, code: string, name: string}}>
     */
    private function billableOrders(Request $request): array
    {
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $query = SalesInvoiceService::billableOrders()->with('customer:id,code,name');

        if ($search !== '') {
            $escaped = ListQuery::escapeLike($search);

            $query->where(function (Builder $inner) use ($escaped): void {
                $inner->where('number', 'LIKE', "%{$escaped}%")
                    ->orWhereHas('customer', function (Builder $customer) use ($escaped): void {
                        $customer->where('name', 'LIKE', "%{$escaped}%");
                    });
            });
        }

        return $query
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(self::BILLABLE_ORDER_LIMIT)
            ->get()
            ->map(fn (SalesOrder $order): array => [
                'id' => $order->id,
                'number' => $order->number,
                'orderDate' => $order->order_date->toDateString(),
                'status' => $order->status->value,
                'total' => $order->total,
                'customer' => [
                    'id' => $order->customer->id,
                    'code' => $order->customer->code,
                    'name' => $order->customer->name,
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function orderProps(SalesOrder $order): array
    {
        $order->loadMissing('customer:id,code,name,address');

        return [
            'id' => $order->id,
            'number' => $order->number,
            'orderDate' => $order->order_date->toDateString(),
            'customer' => [
                'id' => $order->customer->id,
                'code' => $order->customer->code,
                'name' => $order->customer->name,
                'address' => $order->customer->address,
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
            'deliveryOrderItemId' => $line['delivery_order_item_id'],
            'deliveryOrderId' => $line['delivery_order_id'],
            'deliveryOrderNumber' => $line['delivery_order_number'],
            'productId' => $line['product_id'],
            'productName' => $line['product_name'],
            'deliveredQuantity' => $line['delivered_quantity'],
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
    private function detailProps(SalesInvoice $invoice): array
    {
        $invoice->load([
            'customer:id,code,name,address',
            'salesOrder:id,number',
            'poster:id,name',
            'voider:id,name',
            'creator:id,name',
            'items.taxRate:id,code,rate',
            'items.deliveryOrderItem.deliveryOrder:id,number',
        ]);

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
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
            'customer' => [
                'id' => $invoice->customer->id,
                'code' => $invoice->customer->code,
                'name' => $invoice->customer->name,
                'address' => $invoice->customer->address,
            ],
            'salesOrder' => $invoice->salesOrder === null ? null : [
                'id' => $invoice->salesOrder->id,
                'number' => $invoice->salesOrder->number,
            ],
            'creator' => $invoice->creator === null ? null : ['id' => $invoice->creator->id, 'name' => $invoice->creator->name],
            'poster' => $invoice->poster === null ? null : ['id' => $invoice->poster->id, 'name' => $invoice->poster->name],
            'voider' => $invoice->voider === null ? null : ['id' => $invoice->voider->id, 'name' => $invoice->voider->name],
            'postedAt' => $invoice->posted_at?->toIso8601String(),
            'voidedAt' => $invoice->voided_at?->toIso8601String(),
            'voidReason' => $invoice->void_reason,
            'items' => $invoice->items->map(fn (SalesInvoiceItem $item): array => [
                'id' => $item->id,
                'lineNo' => $item->line_no,
                'description' => $item->description,
                'deliveryOrderId' => $item->deliveryOrderItem?->delivery_order_id,
                'deliveryOrderNumber' => $item->deliveryOrderItem?->deliveryOrder->number,
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

    private function isOverdue(SalesInvoice $invoice): bool
    {
        return in_array($invoice->status, [SalesInvoiceStatus::POSTED, SalesInvoiceStatus::PARTIALLY_PAID], true)
            && $invoice->due_date->toDateString() < now()->toDateString()
            && Decimal::cmp($invoice->total, $invoice->paid_amount) > 0;
    }
}
