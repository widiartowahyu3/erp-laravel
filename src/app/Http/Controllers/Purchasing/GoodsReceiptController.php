<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\GoodsReceiptStoreRequest;
use App\Http\Requests\Purchasing\GoodsReceiptUpdateRequest;
use App\Http\Requests\Purchasing\GoodsReceiptVoidRequest;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Accounting\JournalSourceResolver;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Services\Reports\ListExports;
use App\Support\Decimal;
use App\Support\Documents\DocumentPrintProps;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly GoodsReceiptService $grService
    ) {}

    public function index(Request $request, ListExports $exports): Response|StreamedResponse
    {
        $list = ListQuery::for(GoodsReceipt::with(['purchaseOrder:id,number', 'supplier:id,code,name', 'warehouse:id,code,name', 'creator:id,name']))
            ->searchable(['number', 'notes'])
            ->sortable(['id', 'number', 'receipt_date', 'status', 'created_at'])
            ->filterable([
                'supplier_id' => 'supplier_id',
                'warehouse_id' => 'warehouse_id',
                'status' => 'status',
                'date_from' => function ($query, mixed $value): void {
                    $query->where('receipt_date', '>=', $value);
                },
                'date_to' => function ($query, mixed $value): void {
                    $query->where('receipt_date', '<=', $value);
                },
            ])
            ->defaultSort('receipt_date', 'desc');

        if (ListExports::requested($request)) {
            return $exports->goodsReceipts($list->apply($request));
        }

        $paginator = $list->paginate($request, 25);

        return Inertia::render('Purchasing/GoodsReceipts/Index', [
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
            'statuses' => array_map(fn (GoodsReceiptStatus $s) => $s->value, GoodsReceiptStatus::cases()),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $poId = $request->query('purchase_order_id');
        if (! is_numeric($poId) || (int) $poId <= 0) {
            return redirect()
                ->route('purchasing.purchase-orders.index')
                ->with('error', 'Silakan pilih pesanan pembelian terlebih dahulu untuk membuat penerimaan barang.');
        }

        $po = PurchaseOrder::with([
            'items.product.baseUnit:id,code,name',
            'supplier:id,code,name',
            'warehouse:id,code,name',
        ])->find((int) $poId);

        if ($po === null || ($po->status !== PurchaseOrderStatus::APPROVED && $po->status !== PurchaseOrderStatus::PARTIALLY_RECEIVED)) {
            return redirect()
                ->route('purchasing.purchase-orders.index')
                ->with('error', 'Pesanan pembelian tidak ditemukan atau belum disetujui untuk diterima.');
        }

        $eligibleItems = $po->items
            ->filter(fn ($item) => Decimal::cmp($item->remainingQuantity(), '0') > 0)
            ->values();

        if ($eligibleItems->isEmpty()) {
            return redirect()
                ->route('purchasing.purchase-orders.show', $po)
                ->with('error', 'Semua barang pada pesanan pembelian ini telah diterima secara penuh.');
        }

        return Inertia::render('Purchasing/GoodsReceipts/Create', [
            'purchaseOrder' => $po,
            'eligibleItems' => $eligibleItems,
        ]);
    }

    public function store(GoodsReceiptStoreRequest $request): RedirectResponse
    {
        $gr = $this->grService->create($request->validated());

        return redirect()
            ->route('purchasing.goods-receipts.show', $gr)
            ->with('success', 'Penerimaan barang berhasil dibuat.');
    }

    public function show(GoodsReceipt $goodsReceipt, PurchaseInvoiceService $invoices): Response
    {
        $goodsReceipt->load([
            'items.product.baseUnit:id,code,name',
            'items.purchaseOrderItem',
            'purchaseOrder:id,number,order_date,status',
            'supplier:id,code,name',
            'warehouse:id,code,name',
            'creator:id,name',
            'updater:id,name',
            'poster:id,name',
            'voider:id,name',
        ]);

        return Inertia::render('Purchasing/GoodsReceipts/Show', [
            'item' => $goodsReceipt,
            'journal' => JournalSourceResolver::related(StockService::SOURCE_GOODS_RECEIPT, $goodsReceipt->id),
            'hasBillableItems' => $invoices->hasBillableItems($goodsReceipt->purchaseOrder),
        ]);
    }

    public function print(GoodsReceipt $goodsReceipt): Response
    {
        return Inertia::render('Purchasing/GoodsReceipts/Print', [
            'item' => DocumentPrintProps::goodsReceipt($goodsReceipt),
        ]);
    }

    public function edit(GoodsReceipt $goodsReceipt): Response|RedirectResponse
    {
        if (! $goodsReceipt->isEditable()) {
            return redirect()
                ->route('purchasing.goods-receipts.show', $goodsReceipt)
                ->with('error', 'Hanya draf penerimaan barang yang dapat diubah.');
        }

        $goodsReceipt->load([
            'items.product.baseUnit:id,code,name',
            'items.purchaseOrderItem',
            'purchaseOrder.items.product.baseUnit:id,code,name',
            'supplier:id,code,name',
            'warehouse:id,code,name',
        ]);

        return Inertia::render('Purchasing/GoodsReceipts/Edit', [
            'item' => $goodsReceipt,
        ]);
    }

    public function update(GoodsReceiptUpdateRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $updated = $this->grService->update($goodsReceipt, $request->validated());

        return redirect()
            ->route('purchasing.goods-receipts.show', $updated)
            ->with('success', 'Penerimaan barang berhasil diperbarui.');
    }

    public function post(GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->grService->post($goodsReceipt);

        return back()->with('success', 'Penerimaan barang berhasil diposting ke persediaan.');
    }

    public function void(GoodsReceiptVoidRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->grService->void($goodsReceipt, (string) $request->input('reason'));

        return back()->with('success', 'Penerimaan barang berhasil dibatalkan.');
    }
}
