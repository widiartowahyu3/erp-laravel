<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Enums\PaymentMethod;
use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class BasePaymentRequest extends FormRequest
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

        foreach (['reference', 'notes'] as $field) {
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
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', 'string', Rule::enum(PaymentMethod::class)],
            'account_id' => ['required', 'integer', Rule::exists('chart_of_accounts', 'id')],
            'amount' => ['required', new DecimalString(16, 2, '0.01')],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*.sales_invoice_id' => ['required', 'integer', 'distinct', Rule::exists('sales_invoices', 'id')],
            'allocations.*.amount' => ['required', new DecimalString(16, 2, '0.01')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'customer_id' => 'pelanggan',
            'payment_date' => 'tanggal pembayaran',
            'method' => 'metode',
            'account_id' => 'akun kas/bank',
            'amount' => 'jumlah',
            'reference' => 'referensi',
            'notes' => 'catatan',
            'allocations' => 'alokasi',
            'allocations.*.sales_invoice_id' => 'faktur',
            'allocations.*.amount' => 'jumlah alokasi',
        ];
    }
}
