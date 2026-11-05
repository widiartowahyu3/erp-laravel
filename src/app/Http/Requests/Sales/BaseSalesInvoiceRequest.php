<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class BaseSalesInvoiceRequest extends FormRequest
{
    /**
     * Authorization is enforced by the route's can: middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();

        foreach (['notes', 'due_date'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $trimmed = trim($input[$field]);
                $input[$field] = $trimmed !== '' ? $trimmed : null;
            }
        }

        $this->replace($input);
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function sharedRules(): array
    {
        return [
            'invoice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.delivery_order_item_id' => ['required', 'integer', 'distinct', Rule::exists('delivery_order_items', 'id')],
            'items.*.quantity' => ['required', new DecimalString(14, 4, '0.0001')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sales_order_id' => 'pesanan penjualan',
            'invoice_date' => 'tanggal faktur',
            'due_date' => 'tanggal jatuh tempo',
            'notes' => 'catatan',
            'items' => 'baris faktur',
            'items.*.delivery_order_item_id' => 'baris surat jalan',
            'items.*.quantity' => 'kuantitas ditagih',
        ];
    }
}
