<?php

declare(strict_types=1);

namespace App\Support\Sales;

use App\Support\Accounting\InvoiceAmountAllocator;

/**
 * Amounts of a sales invoice, following the shared allocation rules.
 */
final class SalesInvoiceCalculator
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
        return InvoiceAmountAllocator::calculate($lines);
    }
}
