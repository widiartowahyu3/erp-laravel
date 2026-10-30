<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use Illuminate\Validation\Rule;

final class UnitStoreRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:10', Rule::unique('units', 'code')],
            'name' => ['required', 'string', 'max:50'],
        ];
    }
}
