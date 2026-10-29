<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseMasterDataRequest extends FormRequest
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

        if (isset($input['code']) && is_string($input['code'])) {
            $input['code'] = strtoupper((string) preg_replace('/\s+/', ' ', trim($input['code'])));
        }

        if (isset($input['sku']) && is_string($input['sku'])) {
            $input['sku'] = strtoupper((string) preg_replace('/\s+/', ' ', trim($input['sku'])));
        }

        if (isset($input['name']) && is_string($input['name'])) {
            $input['name'] = (string) preg_replace('/\s+/', ' ', trim($input['name']));
        }

        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = strtolower(trim($input['email']));
        }

        if (isset($input['contact_person']) && ! isset($input['contact_name'])) {
            $input['contact_name'] = $input['contact_person'];
        }

        if (isset($input['contact_name']) && is_string($input['contact_name'])) {
            $input['contact_name'] = (string) preg_replace('/\s+/', ' ', trim($input['contact_name']));
        }

        if (isset($input['barcode']) && is_string($input['barcode'])) {
            $input['barcode'] = trim($input['barcode']);
        }

        if (isset($input['rate_percent']) && ! isset($input['rate'])) {
            $input['rate'] = $input['rate_percent'];
        }

        if (isset($input['unit_id']) && ! isset($input['base_unit_id'])) {
            $input['base_unit_id'] = $input['unit_id'];
        }

        $this->replace($input);
    }

    /**
     * Standard friendly unique validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Kode sudah dipakai, termasuk oleh data yang sudah dihapus.',
            'sku.unique' => 'Kode SKU sudah dipakai, termasuk oleh data yang sudah dihapus.',
            'barcode.unique' => 'Kode barcode sudah dipakai, termasuk oleh data yang sudah dihapus.',
        ];
    }
}
