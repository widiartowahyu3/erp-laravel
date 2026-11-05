<?php

declare(strict_types=1);

namespace App\Services\Accounting\Builders;

use App\Models\Payment;
use App\Support\Accounting\JournalDraft;

/**
 * JT-PAYR and JT-PAYD: the cash or bank account chosen on the payment against receivable or payable.
 */
final class PaymentJournalBuilder
{
    public static function receipt(Payment $payment, string $cashAccountCode): JournalDraft
    {
        return (new JournalDraft("Penerimaan pembayaran {$payment->number}"))
            ->debit($cashAccountCode, $payment->amount)
            ->credit('receivable', $payment->amount);
    }

    public static function disbursement(Payment $payment, string $cashAccountCode): JournalDraft
    {
        return (new JournalDraft("Pembayaran ke pemasok {$payment->number}"))
            ->debit('payable', $payment->amount)
            ->credit($cashAccountCode, $payment->amount);
    }
}
