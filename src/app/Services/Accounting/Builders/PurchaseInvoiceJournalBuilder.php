<?php

declare(strict_types=1);

namespace App\Services\Accounting\Builders;

use App\Models\PurchaseInvoice;
use App\Support\Accounting\JournalDraft;
use App\Support\Decimal;

/**
 * JT-PI: the receipt value recognized and input VAT against payable. A rounding difference between
 * the invoice's net amount and that value goes to the inventory adjustment account, on whichever side balances.
 */
final class PurchaseInvoiceJournalBuilder
{
    public static function build(PurchaseInvoice $invoice, string $goodsValue, string $difference): JournalDraft
    {
        $draft = (new JournalDraft("Faktur pembelian {$invoice->number}"))
            ->debit('grir', $goodsValue)
            ->debit('vat_input', $invoice->tax_amount);

        if (Decimal::isNegative($difference)) {
            return $draft
                ->credit('payable', $invoice->total)
                ->credit('inventory_adjustment', Decimal::sub('0', $difference));
        }

        return $draft
            ->debit('inventory_adjustment', $difference)
            ->credit('payable', $invoice->total);
    }
}
