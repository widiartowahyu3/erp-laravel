<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use App\Models\ProductCategory;
use Illuminate\Validation\Rule;

final class ProductCategoryUpdateRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var ProductCategory|string|int|null $category */
        $category = $this->route('product_category');
        $id = is_object($category) ? $category->getKey() : $category;

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('product_categories', 'code')->ignore($id)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('product_categories', 'id')->whereNull('deleted_at'),
                Rule::notIn([$id]),
            ],
        ];
    }
}
