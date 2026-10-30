<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use App\Rules\DecimalString;
use Illuminate\Validation\Rule;

final class ProductStoreRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:50', Rule::unique('products', 'sku')],
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['required', 'integer', Rule::exists('product_categories', 'id')->whereNull('deleted_at')],
            'base_unit_id' => ['required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'barcode' => ['nullable', 'string', 'max:50', Rule::unique('products', 'barcode')],
            'description' => ['nullable', 'string', 'max:1000'],
            'track_stock' => ['boolean'],
            'selling_price' => ['required', new DecimalString(16, 2, '0')],
            'min_stock' => ['required', new DecimalString(14, 4, '0')],
            'is_active' => ['boolean'],
        ];
    }
}
