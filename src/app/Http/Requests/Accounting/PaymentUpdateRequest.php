<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Enums\PaymentType;
use App\Models\Payment;

final class PaymentUpdateRequest extends BasePaymentRequest
{
    protected function paymentType(): ?PaymentType
    {
        $payment = $this->route('payment');

        return $payment instanceof Payment ? $payment->payment_type : null;
    }
}
