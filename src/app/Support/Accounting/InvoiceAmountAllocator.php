<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use App\Support\Decimal;
use App\Support\Documents\LineTotalsCalculator;

/**
 * Amounts of an invoice billed from an order. The discount of an order line is spread in proportion to the
 * quantity billed, and the billing that completes the line takes whatever is left so the discounts add up exactly.
 */
final class InvoiceAmountAllocator
{
    /**
     * @param iterable<int, array{
     *     order_item_key: int,
     *     quantity: string,
     *     unit_price: string,
     *     order_quantity: string,
     *     order_discount: string,
     *     tax_rate_percent: string,
     *     billed_quantity: string,
     *     billed_discount: string
     * }> $lines Lines in invoice order; billed_* cover other invoices only.
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
    public static function calculate(iterable $lines): array
    {
        $running = [];
        $priced = [];

        foreach ($lines as $line) {
            $key = $line['order_item_key'];
            $billedQuantity = $running[$key]['quantity'] ?? $line['billed_quantity'];
            $billedDiscount = $running[$key]['discount'] ?? $line['billed_discount'];

            $discount = self::discountFor($line, $billedQuantity, $billedDiscount);

            $running[$key] = [
                'quantity' => Decimal::add($billedQuantity, $line['quantity'], Decimal::SCALE_QUANTITY),
                'discount' => Decimal::add($billedDiscount, $discount),
            ];

            $priced[] = [
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'discount_amount' => $discount,
                'tax_rate_percent' => $line['tax_rate_percent'],
            ];
        }

        return LineTotalsCalculator::calculate($priced);
    }

    /**
     * @param array{
     *     quantity: string,
     *     unit_price: string,
     *     order_quantity: string,
     *     order_discount: string
     * } $line
     */
    private static function discountFor(array $line, string $billedQuantity, string $billedDiscount): string
    {
        $billedAfter = Decimal::add($billedQuantity, $line['quantity'], Decimal::SCALE_QUANTITY);

        $discount = Decimal::cmp($billedAfter, $line['order_quantity']) === 0
            ? Decimal::sub($line['order_discount'], $billedDiscount)
            : Decimal::div(Decimal::mul($line['order_discount'], $line['quantity'], 6), $line['order_quantity']);

        if (Decimal::isNegative($discount)) {
            return '0.00';
        }

        $gross = Decimal::mul($line['quantity'], $line['unit_price']);

        return Decimal::cmp($discount, $gross) > 0 ? $gross : $discount;
    }
}
