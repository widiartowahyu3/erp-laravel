<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\Reports\ListExports;
use App\Support\Decimal;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StockBalanceController extends Controller
{
    public function index(Request $request, ListExports $exports): Response|StreamedResponse
    {
        $list = ListQuery::for(StockBalance::with(['product.baseUnit', 'product.category', 'warehouse']))
            ->sortable(['quantity', 'avg_cost', 'updated_at'])
            ->filterable([
                'warehouse_id' => 'warehouse_id',
                'product_id' => 'product_id',
                'q' => function (Builder $query, mixed $value): void {
                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }
                    $escaped = ListQuery::escapeLike(trim($value));
                    $query->whereHas('product', function (Builder $q) use ($escaped): void {
                        $q->where('sku', 'LIKE', "%{$escaped}%")
                            ->orWhere('name', 'LIKE', "%{$escaped}%");
                    });
                },
            ])
            ->defaultSort('updated_at', 'desc');

        if (ListExports::requested($request)) {
            return $exports->stockBalances($list->apply($request));
        }

        $paginator = $list->paginate($request, 25);

        return Inertia::render('Inventory/StockBalances/Index', [
            'items' => Paginated::from($paginator, function (StockBalance $balance): array {
                $product = $balance->product;
                $minStock = (string) $product->min_stock;
                $belowMinimum = Decimal::cmp($balance->quantity, $minStock) < 0;
                $stockValue = Decimal::mul($balance->quantity, $balance->avg_cost, Decimal::SCALE_MONEY);

                return [
                    'id' => $balance->id,
                    'product_id' => $balance->product_id,
                    'warehouse_id' => $balance->warehouse_id,
                    'quantity' => $balance->quantity,
                    'avg_cost' => $balance->avg_cost,
                    'stock_value' => $stockValue,
                    'below_minimum' => $belowMinimum,
                    'created_at' => $balance->created_at?->toISOString(),
                    'updated_at' => $balance->updated_at?->toISOString(),
                    'product' => [
                        'id' => $product->id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'min_stock' => $minStock,
                        'category' => $product->category ? [
                            'id' => $product->category->id,
                            'name' => $product->category->name,
                        ] : null,
                        'base_unit' => $product->baseUnit ? [
                            'id' => $product->baseUnit->id,
                            'code' => $product->baseUnit->code,
                            'name' => $product->baseUnit->name,
                        ] : null,
                    ],
                    'warehouse' => [
                        'id' => $balance->warehouse->id,
                        'code' => $balance->warehouse->code,
                        'name' => $balance->warehouse->name,
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
        ]);
    }
}
