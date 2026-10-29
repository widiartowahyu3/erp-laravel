<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use App\Models\Supplier;
use Illuminate\Validation\Rule;

final class SupplierUpdateRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var Supplier|string|int|null $supplier */
        $supplier = $this->route('supplier');
        $id = is_object($supplier) ? $supplier->getKey() : $supplier;

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('suppliers', 'code')->ignore($id)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'payment_term_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')->whereNull('deleted_at')],
            'is_active' => ['boolean'],
        ];
    }
}
