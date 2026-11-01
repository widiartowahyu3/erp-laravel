<?php

declare(strict_types=1);

namespace App\Support\Purchasing;

use App\Support\Documents\LineTotalsCalculator;

final class PurchaseOrderCalculator
{
    /**
     * Calculate line items and header totals for purchase order.
     *
     * @param iterable<int, array{
     *     quantity: string|int|float,
     *     unit_price: string|int|float,
     *     discount_amount?: string|int|float|null,
     *     tax_rate_percent?: string|int|float|null
     * }> $items
     * @return array{
     *     items: list<array{
     *         line_gross: string,
     *         discount_amount: string,
     *         line_net: string,
     *         tax_amount: string,
     *         line_total: string
     *     }>,
     *     header: array{
     *         subtotal: string,
     *         discount_amount: string,
     *         tax_amount: string,
     *         total: string
     *     }
     * }
     */
    public static function calculate(iterable $items): array
    {
        return LineTotalsCalculator::calculate($items);
    }

    /**
     * Calculate net unit cost after discount excluding tax.
     */
    public static function netUnitCost(string $quantity, string $unitPrice, string $discountAmount): string
    {
        return LineTotalsCalculator::netUnitPrice($quantity, $unitPrice, $discountAmount);
    }
}
