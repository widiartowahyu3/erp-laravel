<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use App\Models\Unit;
use Illuminate\Validation\Rule;

final class UnitUpdateRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var Unit|string|int|null $unit */
        $unit = $this->route('unit');
        $id = is_object($unit) ? $unit->getKey() : $unit;

        return [
            'code' => ['required', 'string', 'max:10', Rule::unique('units', 'code')->ignore($id)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:50'],
        ];
    }
}
