<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use App\Rules\DecimalString;
use Illuminate\Validation\Rule;

final class TaxRateStoreRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('tax_rates', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'rate' => ['required', new DecimalString(3, 2, '0', '100')],
            'is_active' => ['boolean'],
        ];
    }
}
