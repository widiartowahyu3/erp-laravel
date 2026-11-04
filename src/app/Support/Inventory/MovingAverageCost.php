<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Support\Decimal;
use InvalidArgumentException;

final class MovingAverageCost
{
    private const string AVG_ROUNDING_ERROR = '0.00005';

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

    /**
     * Calculate the moving average after reversing a receipt at its own unit cost.
     * Formula: (oldQuantity * oldAvgCost - outQuantity * outUnitCost) / (oldQuantity - outQuantity)
     * The average is kept when no quantity remains, matching a regular decrease to zero.
     *
     * @return string|null The new average, or null when the remaining value would be negative.
     *
     * @throws InvalidArgumentException
     */
    public static function afterReversal(
        string $oldQuantity,
        string $oldAvgCost,
        string $outQuantity,
        string $outUnitCost
    ): ?string {
        if (Decimal::cmp($oldAvgCost, '0') < 0) {
            throw new InvalidArgumentException("oldAvgCost must be non-negative, got '{$oldAvgCost}'.");
        }

        if (Decimal::cmp($outQuantity, '0') <= 0) {
            throw new InvalidArgumentException("outQuantity must be greater than zero, got '{$outQuantity}'.");
        }

        if (Decimal::cmp($outUnitCost, '0') < 0) {
            throw new InvalidArgumentException("outUnitCost must be non-negative, got '{$outUnitCost}'.");
        }

        if (Decimal::cmp($outQuantity, $oldQuantity) > 0) {
            throw new InvalidArgumentException("outQuantity '{$outQuantity}' exceeds oldQuantity '{$oldQuantity}'.");
        }

        $remainingQty = Decimal::sub($oldQuantity, $outQuantity, 8);

        if (Decimal::isZero($remainingQty)) {
            return Decimal::round($oldAvgCost, Decimal::SCALE_COST);
        }

        $oldTotal = Decimal::mul($oldQuantity, $oldAvgCost, 8);
        $outTotal = Decimal::mul($outQuantity, $outUnitCost, 8);
        $remainingTotal = Decimal::sub($oldTotal, $outTotal, 8);

        if (Decimal::isNegative($remainingTotal)) {
            // The stored average is rounded to scale 4, so its total can be off by half a unit of that scale per item
            $roundingTolerance = Decimal::mul($oldQuantity, self::AVG_ROUNDING_ERROR, 8);

            if (Decimal::cmp(Decimal::sub('0', $remainingTotal, 8), $roundingTolerance) > 0) {
                return null;
            }

            return Decimal::round('0', Decimal::SCALE_COST);
        }

        return Decimal::div($remainingTotal, $remainingQty, Decimal::SCALE_COST);
    }
}
