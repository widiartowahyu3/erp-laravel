<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use App\Models\TaxRate;
use App\Rules\DecimalString;
use Illuminate\Validation\Rule;

final class TaxRateUpdateRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var TaxRate|string|int|null $taxRate */
        $taxRate = $this->route('tax_rate');
        $id = is_object($taxRate) ? $taxRate->getKey() : $taxRate;

        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('tax_rates', 'code')->ignore($id)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:100'],
            'rate' => ['required', new DecimalString(3, 2, '0', '100')],
            'is_active' => ['boolean'],
        ];
    }
}
