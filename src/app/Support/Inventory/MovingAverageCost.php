<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Support\Decimal;
use InvalidArgumentException;

final class MovingAverageCost
{
    private function __construct() {}

    /**
     * Calculate new moving average unit cost after stock receipt.
     * Formula: (oldQuantity * oldAvgCost + inQuantity * inUnitCost) / (oldQuantity + inQuantity)
     * Calculated with scale 8 for multiplications and additions, then rounded half-up to scale 4.
     *
     * @throws InvalidArgumentException
     */
    public static function afterReceipt(
        string $oldQuantity,
        string $oldAvgCost,
        string $inQuantity,
        string $inUnitCost
    ): string {
        if (Decimal::cmp($oldQuantity, '0') < 0) {
            throw new InvalidArgumentException("oldQuantity must be non-negative, got '{$oldQuantity}'.");
        }

        if (Decimal::cmp($oldAvgCost, '0') < 0) {
            throw new InvalidArgumentException("oldAvgCost must be non-negative, got '{$oldAvgCost}'.");
        }

        if (Decimal::cmp($inQuantity, '0') <= 0) {
            throw new InvalidArgumentException("inQuantity must be greater than zero, got '{$inQuantity}'.");
        }

        if (Decimal::cmp($inUnitCost, '0') < 0) {
            throw new InvalidArgumentException("inUnitCost must be non-negative, got '{$inUnitCost}'.");
        }

        if (Decimal::isZero($oldQuantity)) {
            return Decimal::round($inUnitCost, Decimal::SCALE_COST);
        }

        $oldTotal = Decimal::mul($oldQuantity, $oldAvgCost, 8);
        $inTotal = Decimal::mul($inQuantity, $inUnitCost, 8);
        $totalCost = Decimal::add($oldTotal, $inTotal, 8);
        $totalQty = Decimal::add($oldQuantity, $inQuantity, 8);

        return Decimal::div($totalCost, $totalQty, Decimal::SCALE_COST);
    }
}
