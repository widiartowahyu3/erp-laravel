<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\SalesInvoiceStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\SalesInvoice;
use App\Services\Documents\DocumentStateMachine;
use App\Support\Decimal;

/**
 * Moves money onto and off a sales invoice and keeps its status in step.
 * The caller must already hold the invoice row lock.
 */
final class InvoiceSettlement
{
    /**
     * @throws BusinessRuleException
     */
    public function apply(SalesInvoice $invoice, string $amount): void
    {
        $this->setPaid($invoice, Decimal::add($invoice->paid_amount, $amount));
    }

    /**
     * @throws BusinessRuleException
     */
    public function revert(SalesInvoice $invoice, string $amount): void
    {
        $this->setPaid($invoice, Decimal::sub($invoice->paid_amount, $amount));
    }

    /**
     * @throws BusinessRuleException
     */
    private function setPaid(SalesInvoice $invoice, string $paid): void
    {
        if (Decimal::isNegative($paid) || Decimal::cmp($paid, $invoice->total) > 0) {
            throw new BusinessRuleException("Jumlah dibayar pada faktur {$invoice->number} tidak valid.", 409, 'allocations');
        }

        $status = $this->statusFor($paid, $invoice->total);

        if ($status !== $invoice->status) {
            DocumentStateMachine::assertTransition($invoice->status, $status);
            $invoice->status = $status;
        }

        $invoice->paid_amount = $paid;
        $invoice->save();
    }

    private function statusFor(string $paid, string $total): SalesInvoiceStatus
    {
        if (Decimal::isZero($paid)) {
            return SalesInvoiceStatus::POSTED;
        }

        return Decimal::cmp($paid, $total) === 0
            ? SalesInvoiceStatus::PAID
            : SalesInvoiceStatus::PARTIALLY_PAID;
    }
}
