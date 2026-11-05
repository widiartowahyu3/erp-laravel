<?php

declare(strict_types=1);

namespace App\Services\Accounting\Builders;

use App\Models\Payment;
use App\Support\Accounting\JournalDraft;

/**
 * JT-PAYR: the cash or bank account chosen on the payment against receivable.
 */
final class PaymentJournalBuilder
{
    public static function receipt(Payment $payment, string $cashAccountCode): JournalDraft
    {
        return (new JournalDraft("Penerimaan pembayaran {$payment->number}"))
            ->debit($cashAccountCode, $payment->amount)
            ->credit('receivable', $payment->amount);
    }
}
