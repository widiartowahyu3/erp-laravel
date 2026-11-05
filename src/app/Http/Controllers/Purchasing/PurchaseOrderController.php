<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\PurchaseOrderStoreRequest;
use App\Http\Requests\Purchasing\PurchaseOrderUpdateRequest;
use App\Http\Requests\Purchasing\PurchaseOrderVoidRequest;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Reports\ListExports;
use App\Support\Documents\DocumentPrintProps;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $poService
    ) {}

    public function index(Request $request, ListExports $exports): Response|StreamedResponse
    {
        $list = ListQuery::for(PurchaseOrder::with(['supplier:id,code,name', 'warehouse:id,code,name', 'creator:id,name']))
            ->searchable(['number', 'notes'])
            ->sortable(['id', 'number', 'order_date', 'status', 'total', 'created_at'])
            ->filterable([
                'supplier_id' => 'supplier_id',
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
            return $exports->purchaseOrders($list->apply($request));
        }

        $paginator = $list->paginate($request, 25);

        return Inertia::render('Purchasing/PurchaseOrders/Index', [
            'items' => Paginated::from($paginator, null, $request),
            'suppliers' => Supplier::query()
                ->select(['id', 'code', 'name'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'warehouses' => Warehouse::query()
                ->select(['id', 'code', 'name'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'statuses' => array_map(fn (PurchaseOrderStatus $s) => $s->value, PurchaseOrderStatus::cases()),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Purchasing/PurchaseOrders/Create', [
            'suppliers' => Supplier::query()
                ->select(['id', 'code', 'name', 'payment_term_id'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
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
                ->select(['id', 'sku', 'name', 'base_unit_id'])
                ->where('is_active', true)
                ->where('track_stock', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(PurchaseOrderStoreRequest $request): RedirectResponse
    {
        $po = $this->poService->create($request->validated());

        return redirect()
            ->route('purchasing.purchase-orders.show', $po)
            ->with('success', 'Pesanan pembelian berhasil dibuat.');
    }

    public function show(PurchaseOrder $purchaseOrder, PurchaseInvoiceService $invoices): Response
    {
        $purchaseOrder->load([
            'items.product.baseUnit:id,code,name',
            'items.taxRate:id,code,rate',
            'supplier:id,code,name',
            'warehouse',
            'paymentTerm',
            'goodsReceipts:id,number,purchase_order_id,status,receipt_date',
            'creator:id,name',
            'updater:id,name',
            'approver:id,name',
            'voider:id,name',
        ]);

        return Inertia::render('Purchasing/PurchaseOrders/Show', [
            'item' => $purchaseOrder,
            'hasBillableItems' => $invoices->hasBillableItems($purchaseOrder),
        ]);
    }

    public function print(PurchaseOrder $purchaseOrder): Response
    {
        return Inertia::render('Purchasing/PurchaseOrders/Print', [
            'item' => DocumentPrintProps::purchaseOrder($purchaseOrder),
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        if (! $purchaseOrder->isEditable()) {
            return redirect()
                ->route('purchasing.purchase-orders.show', $purchaseOrder)
                ->with('error', 'Hanya draf pesanan pembelian yang dapat diubah.');
        }

        $purchaseOrder->load([
            'items.product.baseUnit:id,code,name',
            'items.taxRate:id,code,rate',
        ]);

        return Inertia::render('Purchasing/PurchaseOrders/Edit', [
            'item' => $purchaseOrder,
            'suppliers' => Supplier::query()
                ->select(['id', 'code', 'name', 'payment_term_id'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
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
                ->select(['id', 'sku', 'name', 'base_unit_id'])
                ->where('is_active', true)
                ->where('track_stock', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(PurchaseOrderUpdateRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $updated = $this->poService->update($purchaseOrder, $request->validated());

        return redirect()
            ->route('purchasing.purchase-orders.show', $updated)
            ->with('success', 'Pesanan pembelian berhasil diperbarui.');
    }

    public function approve(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->poService->approve($purchaseOrder);

        return back()->with('success', 'Pesanan pembelian berhasil disetujui.');
    }

    public function void(PurchaseOrderVoidRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->poService->void($purchaseOrder, (string) $request->input('reason'));

        return back()->with('success', 'Pesanan pembelian berhasil dibatalkan.');
    }

    public function close(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->poService->close($purchaseOrder);

        return back()->with('success', 'Pesanan pembelian berhasil ditutup.');
    }
}
