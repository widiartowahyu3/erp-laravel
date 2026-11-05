<?php

declare(strict_types=1);

namespace App\Support\Purchasing;

use App\Exceptions\BusinessRuleException;
use App\Support\Accounting\InvoiceAmountAllocator;
use App\Support\Decimal;

/**
 * Amounts of a purchase invoice, plus the receipt value it recognizes for journal JT-PI.
 *
 * The receipt was journaled at V(quantity x unit_cost) per item, so the billing that completes a receipt item
 * takes whatever is left of that value, which leaves the goods received not invoiced account at exactly zero.
 * Order prices and receipt costs agree except for rounding, and anything above the tolerance is refused.
 */
final class PurchaseInvoiceCalculator
{
    private const string UNIT_COST_ROUNDING = '0.00005';

    private const string LINE_ROUNDING = '0.01';

    /**
     * @param iterable<int, array{
     *     order_item_key: int,
     *     receipt_item_key: int,
     *     quantity: string,
     *     unit_price: string,
     *     order_quantity: string,
     *     order_discount: string,
     *     tax_rate_percent: string,
     *     billed_quantity: string,
     *     billed_discount: string,
     *     billed_lines: int,
     *     receipt_quantity: string,
     *     unit_cost: string,
     *     recognized_quantity: string,
     *     recognized_value: string
     * }> $lines Lines in invoice order; billed_* and recognized_* cover other invoices only.
     * @return array{
     *     items: list<array{
     *         line_gross: string,
     *         discount_amount: string,
     *         line_net: string,
     *         tax_amount: string,
     *         line_total: string,
     *         goods_value: string
     *     }>,
     *     header: array{
     *         subtotal: string,
     *         discount_amount: string,
     *         tax_amount: string,
     *         total: string
     *     },
     *     goods_value: string,
     *     difference: string
     * }
     *
     * @throws BusinessRuleException When the difference between net and recognized value exceeds the tolerance.
     */
    public static function calculate(iterable $lines): array
    {
        $lines = [...$lines];
        $amounts = InvoiceAmountAllocator::calculate($lines);

        $recognized = [];
        $ordered = [];
        $items = [];
        $goodsValue = '0.00';
        $tolerance = '0';

        foreach ($lines as $index => $line) {
            $receiptKey = $line['receipt_item_key'];
            $recognizedQuantity = $recognized[$receiptKey]['quantity'] ?? $line['recognized_quantity'];
            $recognizedValue = $recognized[$receiptKey]['value'] ?? $line['recognized_value'];
            $quantityAfter = Decimal::add($recognizedQuantity, $line['quantity'], Decimal::SCALE_QUANTITY);

            $value = Decimal::cmp($quantityAfter, $line['receipt_quantity']) === 0
                ? Decimal::sub(Decimal::mul($line['receipt_quantity'], $line['unit_cost']), $recognizedValue)
                : Decimal::mul($line['quantity'], $line['unit_cost']);

            $recognized[$receiptKey] = [
                'quantity' => $quantityAfter,
                'value' => Decimal::add($recognizedValue, $value),
            ];

            $orderKey = $line['order_item_key'];
            $orderedQuantity = $ordered[$orderKey]['quantity'] ?? $line['billed_quantity'];
            $orderedLines = $ordered[$orderKey]['lines'] ?? $line['billed_lines'];
            $orderedAfter = Decimal::add($orderedQuantity, $line['quantity'], Decimal::SCALE_QUANTITY);

            $ordered[$orderKey] = ['quantity' => $orderedAfter, 'lines' => $orderedLines + 1];

            $carriesResidue = Decimal::cmp($quantityAfter, $line['receipt_quantity']) === 0
                || Decimal::cmp($orderedAfter, $line['order_quantity']) === 0;

            $tolerance = Decimal::add($tolerance, self::lineTolerance($line['quantity'], $orderedAfter, $orderedLines, $carriesResidue), 5);

            $items[] = [...$amounts['items'][$index], 'goods_value' => $value];
            $goodsValue = Decimal::add($goodsValue, $value);
        }

        $net = Decimal::sub($amounts['header']['subtotal'], $amounts['header']['discount_amount']);
        $difference = Decimal::sub($net, $goodsValue);

        self::assertWithinTolerance($difference, $tolerance);

        return [
            'items' => $items,
            'header' => $amounts['header'],
            'goods_value' => $goodsValue,
            'difference' => $difference,
        ];
    }

    /**
     * A plain line can differ by its own rounding. A line that completes an order or receipt item also
     * carries the rounding of every earlier billing of that item.
     */
    private static function lineTolerance(string $quantity, string $orderedAfter, int $earlierLines, bool $carriesResidue): string
    {
        if (! $carriesResidue) {
            return Decimal::add(self::LINE_ROUNDING, Decimal::mul($quantity, self::UNIT_COST_ROUNDING, 5), 5);
        }

        return Decimal::add(
            Decimal::mul(self::LINE_ROUNDING, (string) ($earlierLines + 1), 5),
            Decimal::mul($orderedAfter, self::UNIT_COST_ROUNDING, 5),
            5
        );
    }

    /**
     * @throws BusinessRuleException
     */
    private static function assertWithinTolerance(string $difference, string $tolerance): void
    {
        $size = Decimal::isNegative($difference) ? Decimal::sub('0', $difference) : $difference;

        if (Decimal::cmp($size, $tolerance) > 0) {
            $differenceText = Decimal::trim($difference);

            throw new BusinessRuleException(
                "Nilai faktur tidak cocok dengan nilai penerimaan barang: selisih {$differenceText} melebihi batas pembulatan yang wajar.",
                422,
                'items'
            );
        }
    }
}
