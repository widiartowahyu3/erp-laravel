<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class BasePurchaseInvoiceRequest extends FormRequest
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

        foreach (['notes', 'due_date', 'supplier_invoice_number'] as $field) {
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
            'supplier_invoice_number' => ['required', 'string', 'max:50'],
            'invoice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.goods_receipt_item_id' => ['required', 'integer', 'distinct', Rule::exists('goods_receipt_items', 'id')],
            'items.*.quantity' => ['required', new DecimalString(14, 4, '0.0001')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'purchase_order_id' => 'pesanan pembelian',
            'supplier_invoice_number' => 'nomor faktur pemasok',
            'invoice_date' => 'tanggal faktur',
            'due_date' => 'tanggal jatuh tempo',
            'notes' => 'catatan',
            'items' => 'baris faktur',
            'items.*.goods_receipt_item_id' => 'baris penerimaan barang',
            'items.*.quantity' => 'kuantitas ditagih',
        ];
    }
}
