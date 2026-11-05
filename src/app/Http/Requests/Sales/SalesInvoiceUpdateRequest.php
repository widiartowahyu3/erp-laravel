<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

final class SalesInvoiceUpdateRequest extends BaseSalesInvoiceRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->sharedRules();
    }
}
