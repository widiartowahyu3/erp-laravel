<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class BaseSalesOrderRequest extends FormRequest
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

        if (isset($input['payment_term_id']) && (string) $input['payment_term_id'] === '') {
            $input['payment_term_id'] = null;
        }

        if (isset($input['items']) && is_array($input['items'])) {
            foreach ($input['items'] as $index => $item) {
                if (is_array($item)) {
                    if (isset($item['discount_amount']) && is_string($item['discount_amount'])) {
                        $trimmedDisc = trim($item['discount_amount']);
                        $input['items'][$index]['discount_amount'] = $trimmedDisc !== '' ? $trimmedDisc : '0.00';
                    }
                    if (isset($item['tax_rate_id']) && (string) $item['tax_rate_id'] === '') {
                        $input['items'][$index]['tax_rate_id'] = null;
                    }
                }
            }
        }

        $this->replace($input);
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function baseRules(): array
    {
        return [
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->whereNull('deleted_at')->where('is_active', true),
            ],
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->whereNull('deleted_at')->where('is_active', true),
            ],
            'order_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'payment_term_id' => [
                'nullable',
                'integer',
                Rule::exists('payment_terms', 'id')->whereNull('deleted_at'),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')->whereNull('deleted_at')->where('is_active', true)->where('track_stock', true),
            ],
            'items.*.quantity' => [
                'required',
                new DecimalString(14, 4, '0.0001'),
            ],
            'items.*.unit_price' => [
                'required',
                new DecimalString(14, 4, '0'),
            ],
            'items.*.discount_amount' => [
                'nullable',
                new DecimalString(14, 2, '0'),
            ],
            'items.*.tax_rate_id' => [
                'nullable',
                'integer',
                Rule::exists('tax_rates', 'id')->whereNull('deleted_at')->where('is_active', true),
            ],
        ];
    }
}
