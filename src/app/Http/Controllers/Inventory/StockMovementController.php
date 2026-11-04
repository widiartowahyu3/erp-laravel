<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Inertia\Paginated;
use App\Support\Inventory\StockMovementSources;
use App\Support\Query\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

final class StockMovementController extends Controller
{
    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(StockMovement::with(['product', 'warehouse', 'creator']))
            ->filterable([
                'warehouse_id' => 'warehouse_id',
                'product_id' => 'product_id',
                'movement_type' => 'movement_type',
            ])
            ->defaultSort('movement_date', 'desc')
            ->paginate($request, 25);

        $sourceNumbers = StockMovementSources::numbers($paginator->items());

        return Inertia::render('Inventory/StockMovements/Index', [
            'items' => Paginated::from($paginator, function (StockMovement $movement) use ($sourceNumbers): array {
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

                $sourceLabel = match ($movement->source_type) {
                    'delivery_order_void' => 'Pembatalan Surat Jalan',
                    'delivery_order' => 'Surat Jalan',
                    'goods_receipt_void' => 'Pembatalan Penerimaan Barang',
                    'goods_receipt' => 'Penerimaan Barang',
                    'stock_adjustment' => 'Penyesuaian Stok',
                    'stock_adjustment_void' => 'Pembatalan Penyesuaian Stok',
                    default => $movement->source_type,
                };

                return [
                    'id' => $movement->id,
                    'movement_date' => $movement->movement_date->toISOString(),
                    'product_id' => $movement->product_id,
                    'warehouse_id' => $movement->warehouse_id,
                    'movement_type' => $movement->movement_type->value,
                    'movement_type_label' => $movement->movement_type->label(),
                    'quantity' => $movement->quantity,
                    'unit_cost' => $movement->unit_cost,
                    'balance_after' => $movement->balance_after,
                    'avg_cost_after' => $movement->avg_cost_after,
                    'source_type' => $movement->source_type,
                    'source_id' => $movement->source_id,
                    'source_url' => $sourceUrl,
                    'source_label' => $sourceLabel,
                    'source_number' => $sourceNumbers[$movement->id] ?? null,
                    'created_by' => $movement->created_by,
                    'created_at' => $movement->created_at?->toISOString(),
                    'creator' => $movement->creator ? [
                        'id' => $movement->creator->id,
                        'name' => $movement->creator->name,
                    ] : null,
                    'product' => [
                        'id' => $movement->product->id,
                        'sku' => $movement->product->sku,
                        'name' => $movement->product->name,
                    ],
                    'warehouse' => [
                        'id' => $movement->warehouse->id,
                        'code' => $movement->warehouse->code,
                        'name' => $movement->warehouse->name,
                    ],
                ];
            }, $request),
            'warehouses' => Warehouse::query()
                ->select(['id', 'code', 'name'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'products' => Product::query()
                ->select(['id', 'sku', 'name'])
                ->where('is_active', true)
                ->where('track_stock', true)
                ->orderBy('name')
                ->get(),
            'movementTypes' => array_map(
                fn (StockMovementType $type) => ['value' => $type->value, 'label' => $type->label()],
                StockMovementType::cases()
            ),
        ]);
    }
}
