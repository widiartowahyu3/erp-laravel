<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesOrderStoreRequest;
use App\Http\Requests\Sales\SalesOrderUpdateRequest;
use App\Http\Requests\Sales\SalesOrderVoidRequest;
use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\Reports\ListExports;
use App\Services\Sales\CreditExposureService;
use App\Services\Sales\SalesInvoiceService;
use App\Services\Sales\SalesOrderService;
use App\Support\Documents\DocumentPrintProps;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesOrderController extends Controller
{
    public function __construct(
        private readonly SalesOrderService $soService,
        private readonly CreditExposureService $credit
    ) {}

    public function index(Request $request, ListExports $exports): Response|StreamedResponse
    {
        $list = ListQuery::for(SalesOrder::with(['customer:id,code,name', 'warehouse:id,code,name', 'creator:id,name']))
            ->searchable(['number', 'notes'])
            ->sortable(['id', 'number', 'order_date', 'status', 'total', 'created_at'])
            ->filterable([
                'customer_id' => 'customer_id',
                'warehouse_id' => 'warehouse_id',
                'status' => 'status',
                'date_from' => function ($query, mixed $value): void {
                    $query->where('order_date', '>=', $value);
                },
                'date_to' => function ($query, mixed $value): void {
                    $query->where('order_date', '<=', $value);
                },
            ])
            ->defaultSort('order_date', 'desc');

        if (ListExports::requested($request)) {
            return $exports->salesOrders($list->apply($request));
        }

        $paginator = $list->paginate($request, 25);

        return Inertia::render('Sales/SalesOrders/Index', [
            'items' => Paginated::from($paginator, null, $request),
            'customers' => Customer::query()
                ->select(['id', 'code', 'name'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'warehouses' => Warehouse::query()
                ->select(['id', 'code', 'name'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'statuses' => array_map(fn (SalesOrderStatus $s) => $s->value, SalesOrderStatus::cases()),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sales/SalesOrders/Create', [
            'customers' => $this->customerOptions(),
            'warehouses' => Warehouse::query()
                ->select(['id', 'code', 'name'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'paymentTerms' => PaymentTerm::query()
                ->select(['id', 'code', 'name', 'days'])
                ->orderBy('days')
                ->get(),
            'taxRates' => TaxRate::query()
                ->select(['id', 'code', 'name', 'rate'])
                ->where('is_active', true)
                ->orderBy('code')
                ->get(),
            'products' => Product::query()
                ->with('baseUnit:id,code,name')
                ->select(['id', 'sku', 'name', 'base_unit_id', 'selling_price'])
                ->where('is_active', true)
                ->where('track_stock', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(SalesOrderStoreRequest $request): RedirectResponse
    {
        $so = $this->soService->create($request->validated());

        return redirect()
            ->route('sales.sales-orders.show', $so)
            ->with('success', 'Pesanan penjualan berhasil dibuat.');
    }

    public function show(SalesOrder $salesOrder, SalesInvoiceService $invoices): Response
    {
        $salesOrder->load([
            'items.product.baseUnit:id,code,name',
            'items.taxRate:id,code,rate',
            'customer:id,code,name,address',
            'warehouse',
            'paymentTerm',
            'deliveryOrders:id,number,sales_order_id,status,delivery_date',
            'creator:id,name',
            'updater:id,name',
            'approver:id,name',
            'voider:id,name',
        ]);

        return Inertia::render('Sales/SalesOrders/Show', [
            'item' => $salesOrder,
            'hasBillableItems' => $invoices->hasBillableItems($salesOrder),
        ]);
    }

    public function print(SalesOrder $salesOrder): Response
    {
        return Inertia::render('Sales/SalesOrders/Print', [
            'item' => DocumentPrintProps::salesOrder($salesOrder),
        ]);
    }

    public function edit(SalesOrder $salesOrder): Response|RedirectResponse
    {
        if (! $salesOrder->isEditable()) {
            return redirect()
                ->route('sales.sales-orders.show', $salesOrder)
                ->with('error', 'Hanya draf pesanan penjualan yang dapat diubah.');
        }

        $salesOrder->load([
            'items.product.baseUnit:id,code,name',
            'items.taxRate:id,code,rate',
        ]);

        return Inertia::render('Sales/SalesOrders/Edit', [
            'item' => $salesOrder,
            'customers' => $this->customerOptions(),
            'warehouses' => Warehouse::query()
                ->select(['id', 'code', 'name'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'paymentTerms' => PaymentTerm::query()
                ->select(['id', 'code', 'name', 'days'])
                ->orderBy('days')
                ->get(),
            'taxRates' => TaxRate::query()
                ->select(['id', 'code', 'name', 'rate'])
                ->where('is_active', true)
                ->orderBy('code')
                ->get(),
            'products' => Product::query()
                ->with('baseUnit:id,code,name')
                ->select(['id', 'sku', 'name', 'base_unit_id', 'selling_price'])
                ->where('is_active', true)
                ->where('track_stock', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(SalesOrderUpdateRequest $request, SalesOrder $salesOrder): RedirectResponse
    {
        $updated = $this->soService->update($salesOrder, $request->validated());

        return redirect()
            ->route('sales.sales-orders.show', $updated)
            ->with('success', 'Pesanan penjualan berhasil diperbarui.');
    }

    public function approve(SalesOrder $salesOrder): RedirectResponse
    {
        $this->soService->approve($salesOrder);

        return back()->with('success', 'Pesanan penjualan berhasil disetujui.');
    }

    public function void(SalesOrderVoidRequest $request, SalesOrder $salesOrder): RedirectResponse
    {
        $this->soService->void($salesOrder, (string) $request->input('reason'));

        return back()->with('success', 'Pesanan penjualan berhasil dibatalkan.');
    }

    public function close(SalesOrder $salesOrder): RedirectResponse
    {
        $this->soService->close($salesOrder);

        return back()->with('success', 'Pesanan penjualan berhasil ditutup.');
    }

    /**
     * Active customers with their credit position, using one query for all outstanding amounts.
     *
     * @return list<array<string, mixed>>
     */
    private function customerOptions(): array
    {
        $customers = Customer::query()
            ->select(['id', 'code', 'name', 'payment_term_id', 'address', 'credit_limit'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $outstanding = $this->credit->outstandingFor($customers->modelKeys());

        return $customers->map(fn (Customer $customer): array => [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'payment_term_id' => $customer->payment_term_id,
            'address' => $customer->address,
            'creditLimit' => (string) $customer->credit_limit,
            'outstandingReceivable' => $outstanding[$customer->id] ?? '0.00',
        ])->values()->all();
    }
}
