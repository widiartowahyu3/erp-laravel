<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Validation\Rule;

final class SalesInvoiceStoreRequest extends BaseSalesInvoiceRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'sales_order_id' => ['required', 'integer', Rule::exists('sales_orders', 'id')],
            ...$this->sharedRules(),
        ];
    }
}
