<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use Illuminate\Validation\Rule;

final class PaymentTermStoreRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('payment_terms', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'days' => ['required', 'integer', 'min:0', 'max:3650'],
        ];
    }
}
