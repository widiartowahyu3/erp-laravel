<?php

declare(strict_types=1);

namespace App\Support\Purchasing;

use App\Support\Decimal;

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
        $calculatedItems = [];
        $headerSubtotal = '0.00';
        $headerDiscount = '0.00';
        $headerTax = '0.00';
        $headerTotal = '0.00';

        foreach ($items as $item) {
            $qty = (string) $item['quantity'];
            $price = (string) $item['unit_price'];
            $disc = isset($item['discount_amount']) && trim((string) $item['discount_amount']) !== ''
                ? (string) $item['discount_amount']
                : '0.00';
            $taxRate = isset($item['tax_rate_percent']) && trim((string) $item['tax_rate_percent']) !== ''
                ? (string) $item['tax_rate_percent']
                : '0.00';

            $disc = Decimal::round($disc, Decimal::SCALE_MONEY);

            // 1. Gross amount: quantity * unit_price
            $lineGross = Decimal::mul($qty, $price, Decimal::SCALE_MONEY);

            // 2. Net amount: gross - discount
            $lineNet = Decimal::sub($lineGross, $disc, Decimal::SCALE_MONEY);
            if (Decimal::isNegative($lineNet)) {
                $lineNet = '0.00';
            }

            // 3. Tax amount: round(net * (rate / 100), 2)
            $taxAmount = '0.00';
            if (Decimal::cmp($taxRate, '0') > 0) {
                $rateFactor = Decimal::div($taxRate, '100', Decimal::SCALE_PERCENT + 4);
                $taxAmount = Decimal::mul($lineNet, $rateFactor, Decimal::SCALE_MONEY);
            }

            // 4. Line total: net + tax
            $lineTotal = Decimal::add($lineNet, $taxAmount, Decimal::SCALE_MONEY);

            $calculatedItems[] = [
                'line_gross' => $lineGross,
                'discount_amount' => $disc,
                'line_net' => $lineNet,
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
            ];

            $headerSubtotal = Decimal::add($headerSubtotal, $lineGross, Decimal::SCALE_MONEY);
            $headerDiscount = Decimal::add($headerDiscount, $disc, Decimal::SCALE_MONEY);
            $headerTax = Decimal::add($headerTax, $taxAmount, Decimal::SCALE_MONEY);
            $headerTotal = Decimal::add($headerTotal, $lineTotal, Decimal::SCALE_MONEY);
        }

        return [
            'items' => $calculatedItems,
            'header' => [
                'subtotal' => $headerSubtotal,
                'discount_amount' => $headerDiscount,
                'tax_amount' => $headerTax,
                'total' => $headerTotal,
            ],
        ];
    }

    /**
     * Calculate net unit cost after discount excluding tax.
     */
    public static function netUnitCost(string $quantity, string $unitPrice, string $discountAmount): string
    {
        $lineGross = Decimal::mul($quantity, $unitPrice, Decimal::SCALE_MONEY);
        $net = Decimal::round(
            Decimal::sub($lineGross, Decimal::round($discountAmount, Decimal::SCALE_MONEY), Decimal::SCALE_MONEY),
            Decimal::SCALE_MONEY
        );

        if (Decimal::isNegative($net)) {
            $net = '0.00';
        }

        return Decimal::div($net, $quantity, Decimal::SCALE_COST);
    }
}
