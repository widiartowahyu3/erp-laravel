<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use Illuminate\Validation\Rule;

final class WarehouseStoreRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('warehouses', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }
}
