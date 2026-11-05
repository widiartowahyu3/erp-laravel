<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Enums\DeliveryOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\DeliveryOrderStoreRequest;
use App\Http\Requests\Sales\DeliveryOrderUpdateRequest;
use App\Http\Requests\Sales\DeliveryOrderVoidRequest;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\Accounting\JournalSourceResolver;
use App\Services\Inventory\StockService;
use App\Services\Sales\DeliveryOrderService;
use App\Services\Sales\SalesInvoiceService;
use App\Support\Decimal;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeliveryOrderController extends Controller
{
    public function __construct(
        private readonly DeliveryOrderService $doService
    ) {}

    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(DeliveryOrder::with(['salesOrder:id,number', 'customer:id,code,name', 'warehouse:id,code,name', 'creator:id,name']))
            ->searchable(['number', 'notes'])
            ->sortable(['id', 'number', 'delivery_date', 'status', 'created_at'])
            ->filterable([
                'customer_id' => 'customer_id',
                'warehouse_id' => 'warehouse_id',
                'status' => 'status',
                'date_from' => function ($query, mixed $value): void {
                    $query->where('delivery_date', '>=', $value);
                },
                'date_to' => function ($query, mixed $value): void {
                    $query->where('delivery_date', '<=', $value);
                },
            ])
            ->defaultSort('delivery_date', 'desc')
            ->paginate($request, 25);

        return Inertia::render('Sales/DeliveryOrders/Index', [
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
            'statuses' => array_map(fn (DeliveryOrderStatus $s) => $s->value, DeliveryOrderStatus::cases()),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        // S13: DO create from SO uses query param sales_order_id (never so_id)
        $soId = $request->query('sales_order_id');
        if (! is_numeric($soId) || (int) $soId <= 0) {
            return redirect()
                ->route('sales.sales-orders.index')
                ->with('error', 'Silakan pilih pesanan penjualan terlebih dahulu untuk membuat surat jalan.');
        }

        $so = SalesOrder::with([
            'items.product.baseUnit:id,code,name',
            'customer:id,code,name,address',
            'warehouse:id,code,name',
        ])->find((int) $soId);

        if ($so === null || ($so->status !== SalesOrderStatus::APPROVED && $so->status !== SalesOrderStatus::PARTIALLY_DELIVERED)) {
            return redirect()
                ->route('sales.sales-orders.index')
                ->with('error', 'Pesanan penjualan tidak ditemukan atau belum disetujui untuk dikirim.');
        }

        $eligibleItems = $so->items
            ->filter(fn ($item) => Decimal::cmp($item->remainingQuantity(), '0') > 0)
            ->values();

        if ($eligibleItems->isEmpty()) {
            return redirect()
                ->route('sales.sales-orders.show', $so)
                ->with('error', 'Semua barang pada pesanan penjualan ini telah dikirim secara penuh.');
        }

        return Inertia::render('Sales/DeliveryOrders/Create', [
            'salesOrder' => $so,
            'eligibleItems' => $eligibleItems,
        ]);
    }

    public function store(DeliveryOrderStoreRequest $request): RedirectResponse
    {
        $do = $this->doService->create($request->validated());

        return redirect()
            ->route('sales.delivery-orders.show', $do)
            ->with('success', 'Surat jalan berhasil dibuat.');
    }

    public function show(DeliveryOrder $deliveryOrder, SalesInvoiceService $invoices): Response
    {
        $deliveryOrder->load([
            'items.product.baseUnit:id,code,name',
            'items.salesOrderItem',
            'salesOrder:id,number,order_date,status',
            'customer:id,code,name,address',
            'warehouse:id,code,name',
            'creator:id,name',
            'updater:id,name',
            'poster:id,name',
            'voider:id,name',
        ]);

        return Inertia::render('Sales/DeliveryOrders/Show', [
            'item' => $deliveryOrder,
            'journal' => JournalSourceResolver::related(StockService::SOURCE_DELIVERY_ORDER, $deliveryOrder->id),
            'hasBillableItems' => $invoices->hasBillableItems($deliveryOrder->salesOrder),
        ]);
    }

    public function edit(DeliveryOrder $deliveryOrder): Response|RedirectResponse
    {
        if (! $deliveryOrder->isEditable()) {
            return redirect()
                ->route('sales.delivery-orders.show', $deliveryOrder)
                ->with('error', 'Hanya draf surat jalan yang dapat diubah.');
        }

        $deliveryOrder->load([
            'items.product.baseUnit:id,code,name',
            'items.salesOrderItem',
            'salesOrder.items.product.baseUnit:id,code,name',
            'customer:id,code,name,address',
            'warehouse:id,code,name',
        ]);

        return Inertia::render('Sales/DeliveryOrders/Edit', [
            'item' => $deliveryOrder,
        ]);
    }

    public function update(DeliveryOrderUpdateRequest $request, DeliveryOrder $deliveryOrder): RedirectResponse
    {
        $updated = $this->doService->update($deliveryOrder, $request->validated());

        return redirect()
            ->route('sales.delivery-orders.show', $updated)
            ->with('success', 'Surat jalan berhasil diperbarui.');
    }

    public function post(DeliveryOrder $deliveryOrder): RedirectResponse
    {
        $this->doService->post($deliveryOrder);

        return back()->with('success', 'Surat jalan berhasil diposting dan stok telah diperbarui.');
    }

    public function void(DeliveryOrderVoidRequest $request, DeliveryOrder $deliveryOrder): RedirectResponse
    {
        $this->doService->void($deliveryOrder, (string) $request->input('reason'));

        return back()->with('success', 'Surat jalan berhasil dibatalkan.');
    }
}
