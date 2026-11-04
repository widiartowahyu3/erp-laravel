<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\DeliveryOrder;
use App\Models\GoodsReceipt;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Services\Inventory\StockService;

final class StockMovementSources
{
    private function __construct() {}

    /**
     * Resolve the document number of each movement with one query per document type.
     *
     * @param  iterable<StockMovement>  $movements
     * @return array<int, string|null> Document number keyed by movement id.
     */
    public static function numbers(iterable $movements): array
    {
        $groups = [
            StockService::SOURCE_GOODS_RECEIPT => [],
            StockService::SOURCE_DELIVERY_ORDER => [],
            StockService::SOURCE_STOCK_ADJUSTMENT => [],
        ];

        foreach ($movements as $movement) {
            $group = self::groupOf($movement->source_type);
            if ($group !== null) {
                $groups[$group][] = $movement->source_id;
            }
        }

        $lookup = [
            StockService::SOURCE_GOODS_RECEIPT => GoodsReceipt::query()->whereIn('id', $groups[StockService::SOURCE_GOODS_RECEIPT])->pluck('number', 'id')->all(),
            StockService::SOURCE_DELIVERY_ORDER => DeliveryOrder::query()->whereIn('id', $groups[StockService::SOURCE_DELIVERY_ORDER])->pluck('number', 'id')->all(),
            StockService::SOURCE_STOCK_ADJUSTMENT => StockAdjustment::query()->whereIn('id', $groups[StockService::SOURCE_STOCK_ADJUSTMENT])->pluck('number', 'id')->all(),
        ];

        $numbers = [];

        foreach ($movements as $movement) {
            $group = self::groupOf($movement->source_type);
            $numbers[$movement->id] = $group !== null ? ($lookup[$group][$movement->source_id] ?? null) : null;
        }

        return $numbers;
    }

    /**
     * Voids point at the same document as the movement they reverse.
     */
    private static function groupOf(string $sourceType): ?string
    {
        return match ($sourceType) {
            StockService::SOURCE_GOODS_RECEIPT, StockService::SOURCE_GOODS_RECEIPT_VOID => StockService::SOURCE_GOODS_RECEIPT,
            StockService::SOURCE_DELIVERY_ORDER, StockService::SOURCE_DELIVERY_ORDER_VOID => StockService::SOURCE_DELIVERY_ORDER,
            StockService::SOURCE_STOCK_ADJUSTMENT, StockService::SOURCE_STOCK_ADJUSTMENT_VOID => StockService::SOURCE_STOCK_ADJUSTMENT,
            default => null,
        };
    }
}
