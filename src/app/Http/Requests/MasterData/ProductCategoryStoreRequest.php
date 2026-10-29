<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use Illuminate\Validation\Rule;

final class ProductCategoryStoreRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('product_categories', 'code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->whereNull('deleted_at')],
        ];
    }
}
