<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use App\Models\Product;
use App\Rules\DecimalString;
use Illuminate\Validation\Rule;

final class ProductUpdateRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var Product|string|int|null $product */
        $product = $this->route('product');
        $id = is_object($product) ? $product->getKey() : $product;

        return [
            'sku' => ['required', 'string', 'max:50', Rule::unique('products', 'sku')->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['required', 'integer', Rule::exists('product_categories', 'id')->whereNull('deleted_at')],
            'base_unit_id' => ['required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'barcode' => ['nullable', 'string', 'max:50', Rule::unique('products', 'barcode')->ignore($id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'track_stock' => ['boolean'],
            'selling_price' => ['required', new DecimalString(16, 2, '0')],
            'min_stock' => ['required', new DecimalString(14, 4, '0')],
            'is_active' => ['boolean'],
        ];
    }
}
