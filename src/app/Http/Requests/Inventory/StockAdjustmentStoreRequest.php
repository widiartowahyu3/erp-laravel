<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Rules\DecimalString;
use Illuminate\Validation\Rule;

final class StockAdjustmentStoreRequest extends BaseStockAdjustmentRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->whereNull('deleted_at')->where('is_active', true),
            ],
            'adjustment_date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')->whereNull('deleted_at')->where('is_active', true)->where('track_stock', true),
            ],
            'items.*.counted_quantity' => [
                'required',
                new DecimalString(14, 4, '0'),
            ],
            'items.*.unit_cost' => [
                'nullable',
                new DecimalString(14, 4, '0'),
            ],
        ];
    }
}
