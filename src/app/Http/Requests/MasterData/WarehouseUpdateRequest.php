<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use App\Models\Warehouse;
use Illuminate\Validation\Rule;

final class WarehouseUpdateRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var Warehouse|string|int|null $warehouse */
        $warehouse = $this->route('warehouse');
        $id = is_object($warehouse) ? $warehouse->getKey() : $warehouse;

        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('warehouses', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }
}
