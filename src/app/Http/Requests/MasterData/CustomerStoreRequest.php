<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use App\Rules\DecimalString;
use Illuminate\Validation\Rule;

final class CustomerStoreRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('customers', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'credit_limit' => ['nullable', new DecimalString(16, 2, '0')],
            'payment_term_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')->whereNull('deleted_at')],
            'is_active' => ['boolean'],
        ];
    }
}
