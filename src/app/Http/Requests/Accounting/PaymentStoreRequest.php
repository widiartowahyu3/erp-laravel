<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Enums\PaymentType;
use Illuminate\Validation\Rule;

final class PaymentStoreRequest extends BasePaymentRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'payment_type' => ['nullable', 'string', Rule::enum(PaymentType::class)],
            ...parent::rules(),
        ];
    }
}
