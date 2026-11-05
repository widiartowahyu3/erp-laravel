<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

final class PurchaseInvoiceUpdateRequest extends BasePurchaseInvoiceRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->sharedRules();
    }
}
