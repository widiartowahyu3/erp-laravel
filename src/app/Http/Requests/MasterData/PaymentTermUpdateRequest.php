<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use App\Models\PaymentTerm;
use Illuminate\Validation\Rule;

final class PaymentTermUpdateRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var PaymentTerm|string|int|null $paymentTerm */
        $paymentTerm = $this->route('payment_term');
        $id = is_object($paymentTerm) ? $paymentTerm->getKey() : $paymentTerm;

        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('payment_terms', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:100'],
            'days' => ['required', 'integer', 'min:0', 'max:3650'],
        ];
    }
}
