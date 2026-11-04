<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockCardRequest;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Decimal;
use App\Support\Inertia\Paginated;
use App\Support\Inventory\StockMovementSources;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

final class StockCardController extends Controller
{
    public function index(StockCardRequest $request): Response
    {
        $productId = $request->input('product_id') ? (int) $request->input('product_id') : null;
        $warehouseId = $request->input('warehouse_id') ? (int) $request->input('warehouse_id') : null;
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $activeWarehouses = Warehouse::query()
            ->select(['id', 'code', 'name'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $activeProducts = Product::query()
            ->select(['id', 'sku', 'name'])
            ->where('is_active', true)
            ->where('track_stock', true)
            ->orderBy('name')
            ->get();

        if ($productId === null || $warehouseId === null) {
            return Inertia::render('Inventory/StockCard/Index', [
                'filters' => [
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ],
                'opening' => null,
                'rows' => null,
                'current' => null,
                'products' => $activeProducts,
                'warehouses' => $activeWarehouses,
            ]);
        }

        // 1. Calculate opening balance
        $opening = [
            'quantity' => '0.0000',
            'avg_cost' => '0.0000',
        ];

        if (is_string($dateFrom) && $dateFrom !== '') {
            $lastBefore = StockMovement::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->where('movement_date', '<', "{$dateFrom} 00:00:00")
                ->orderBy('movement_date', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            if ($lastBefore !== null) {
                $opening = [
                    'quantity' => $lastBefore->balance_after,
                    'avg_cost' => $lastBefore->avg_cost_after,
                ];
            }
        }

        // 2. Query movements within date range
        $query = StockMovement::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId);

        if (is_string($dateFrom) && $dateFrom !== '') {
            $query->where('movement_date', '>=', "{$dateFrom} 00:00:00");
        }

        if (is_string($dateTo) && $dateTo !== '') {
            $query->where('movement_date', '<=', "{$dateTo} 23:59:59");
        }

        $paginator = $query->orderBy('movement_date', 'asc')
            ->orderBy('id', 'asc')
            ->paginate(50)
            ->withQueryString();

        $sourceNumbers = StockMovementSources::numbers($paginator->items());

        $rows = Paginated::from($paginator, function (StockMovement $movement) use ($sourceNumbers): array {
            $cmp = Decimal::cmp($movement->quantity, '0');
            $in = $cmp > 0 ? $movement->quantity : null;
            $out = $cmp < 0 ? substr($movement->quantity, 1) : null;

            $sourceUrl = match ($movement->source_type) {
                'stock_adjustment', 'stock_adjustment_void' => Route::has('inventory.stock-adjustments.show')
                    ? route('inventory.stock-adjustments.show', $movement->source_id)
                    : null,
                'goods_receipt', 'goods_receipt_void' => Route::has('purchasing.goods-receipts.show')
                    ? route('purchasing.goods-receipts.show', $movement->source_id)
                    : null,
                'delivery_order', 'delivery_order_void' => Route::has('sales.delivery-orders.show')
                    ? route('sales.delivery-orders.show', $movement->source_id)
                    : null,
                default => null,
            };

            return [
                'id' => $movement->id,
                'movement_date' => $movement->movement_date->toISOString(),
                'movement_type' => $movement->movement_type->value,
                'movement_type_label' => $movement->movement_type->label(),
                'source_type' => $movement->source_type,
                'source_id' => $movement->source_id,
                'source_url' => $sourceUrl,
                'source_number' => $sourceNumbers[$movement->id] ?? null,
                'in' => $in,
                'out' => $out,
                'unit_cost' => $movement->unit_cost,
                'balance_after' => $movement->balance_after,
                'avg_cost_after' => $movement->avg_cost_after,
            ];
        }, $request);

        // 3. Current snapshot from stock_balances
        $balance = StockBalance::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        $current = $balance !== null ? [
            'quantity' => $balance->quantity,
            'avg_cost' => $balance->avg_cost,
        ] : [
            'quantity' => '0.0000',
            'avg_cost' => '0.0000',
        ];

        return Inertia::render('Inventory/StockCard/Index', [
            'filters' => [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'opening' => $opening,
            'rows' => $rows,
            'current' => $current,
            'products' => $activeProducts,
            'warehouses' => $activeWarehouses,
        ]);
    }
}
