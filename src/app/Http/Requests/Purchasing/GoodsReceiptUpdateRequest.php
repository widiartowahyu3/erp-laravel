<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GoodsReceiptUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize incoming input fields.
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();

        if (isset($input['notes']) && is_string($input['notes'])) {
            $trimmed = trim($input['notes']);
            $input['notes'] = $trimmed !== '' ? $trimmed : null;
        }

        $this->replace($input);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'receipt_date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.purchase_order_item_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('purchase_order_items', 'id'),
            ],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at')->where('is_active', true)->where('track_stock', true),
            ],
            'items.*.quantity' => [
                'required',
                new DecimalString(14, 4, '0.0001'),
            ],
        ];
    }
}
