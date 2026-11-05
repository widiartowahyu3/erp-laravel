<?php

declare(strict_types=1);

namespace App\Services\Accounting\Builders;

use App\Models\SalesInvoice;
use App\Support\Accounting\JournalDraft;

/**
 * JT-SI: receivable and sales discount against sales and output VAT.
 */
final class SalesInvoiceJournalBuilder
{
    public static function build(SalesInvoice $invoice): JournalDraft
    {
        return (new JournalDraft("Faktur penjualan {$invoice->number}"))
            ->debit('receivable', $invoice->total)
            ->debit('sales_discount', $invoice->discount_amount)
            ->credit('sales', $invoice->subtotal)
            ->credit('vat_output', $invoice->tax_amount);
    }
}
