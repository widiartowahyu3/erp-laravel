<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Enums\StockAdjustmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockAdjustmentStoreRequest;
use App\Http\Requests\Inventory\StockAdjustmentUpdateRequest;
use App\Http\Requests\Inventory\StockAdjustmentVoidRequest;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Accounting\JournalSourceResolver;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockService;
use App\Support\Documents\DocumentPrintProps;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class StockAdjustmentController extends Controller
{
    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService
    ) {}

    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(StockAdjustment::with('warehouse'))
            ->searchable(['number', 'reason'])
            ->sortable(['id', 'number', 'adjustment_date', 'status', 'created_at'])
            ->filterable([
                'status' => 'status',
                'warehouse_id' => 'warehouse_id',
            ])
            ->defaultSort('adjustment_date', 'desc')
            ->paginate($request, 25);

        return Inertia::render('Inventory/StockAdjustments/Index', [
            'items' => Paginated::from($paginator, null, $request),
            'warehouses' => Warehouse::query()
                ->select(['id', 'code', 'name'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'statuses' => array_map(
                fn (StockAdjustmentStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                StockAdjustmentStatus::cases()
            ),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/StockAdjustments/Create', [
            'warehouses' => Warehouse::query()
                ->select(['id', 'code', 'name'])
                ->where('is_active', true)
                ->orderBy('name')
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

    public function store(StockAdjustmentStoreRequest $request): RedirectResponse
    {
        $adjustment = $this->stockAdjustmentService->create($request->validated());

        return redirect()
            ->route('inventory.stock-adjustments.show', $adjustment)
            ->with('success', 'Penyesuaian stok berhasil disimpan.');
    }

    public function show(StockAdjustment $stockAdjustment, Request $request): Response
    {
        $stockAdjustment->load([
            'warehouse:id,code,name',
            'items.product.baseUnit:id,code,name',
            'poster:id,name',
            'voider:id,name',
            'creator:id,name',
        ]);

        $movements = null;
        if ($request->user()?->can('stock_movements.view')) {
            $movements = StockMovement::query()
                ->whereIn('source_type', [
                    StockService::SOURCE_STOCK_ADJUSTMENT,
                    StockService::SOURCE_STOCK_ADJUSTMENT_VOID,
                ])
                ->where('source_id', $stockAdjustment->id)
                ->orderBy('id', 'asc')
                ->get();
        }

        return Inertia::render('Inventory/StockAdjustments/Show', [
            'item' => $stockAdjustment,
            'movements' => $movements,
            'journal' => JournalSourceResolver::related(StockService::SOURCE_STOCK_ADJUSTMENT, $stockAdjustment->id),
        ]);
    }

    public function print(StockAdjustment $stockAdjustment): Response
    {
        return Inertia::render('Inventory/StockAdjustments/Print', [
            'item' => DocumentPrintProps::stockAdjustment($stockAdjustment),
        ]);
    }

    public function edit(StockAdjustment $stockAdjustment): Response|RedirectResponse
    {
        if ($stockAdjustment->status !== StockAdjustmentStatus::DRAFT) {
            return redirect()
                ->route('inventory.stock-adjustments.show', $stockAdjustment)
                ->with('error', 'Hanya draf penyesuaian stok yang dapat diubah.');
        }

        $stockAdjustment->load(['items.product.baseUnit:id,code,name']);

        return Inertia::render('Inventory/StockAdjustments/Edit', [
            'item' => $stockAdjustment,
            'warehouses' => Warehouse::query()
                ->select(['id', 'code', 'name'])
                ->where('is_active', true)
                ->orderBy('name')
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

    public function update(StockAdjustmentUpdateRequest $request, StockAdjustment $stockAdjustment): RedirectResponse
    {
        $updated = $this->stockAdjustmentService->update($stockAdjustment, $request->validated());

        return redirect()
            ->route('inventory.stock-adjustments.show', $updated)
            ->with('success', 'Penyesuaian stok berhasil diperbarui.');
    }

    public function post(StockAdjustment $stockAdjustment): RedirectResponse
    {
        $this->stockAdjustmentService->post($stockAdjustment);

        return back()->with('success', 'Penyesuaian stok berhasil diposting.');
    }

    public function void(StockAdjustmentVoidRequest $request, StockAdjustment $stockAdjustment): RedirectResponse
    {
        $this->stockAdjustmentService->void($stockAdjustment, (string) $request->input('reason'));

        return back()->with('success', 'Penyesuaian stok berhasil dibatalkan.');
    }
}
