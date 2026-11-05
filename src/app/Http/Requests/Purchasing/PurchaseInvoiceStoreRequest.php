<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use Illuminate\Validation\Rule;

final class PurchaseInvoiceStoreRequest extends BasePurchaseInvoiceRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'integer', Rule::exists('purchase_orders', 'id')],
            ...$this->sharedRules(),
        ];
    }
}
