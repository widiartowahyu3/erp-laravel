<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

final class GoodsReceiptVoidRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare inputs for validation.
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();

        if (isset($input['reason']) && is_string($input['reason'])) {
            $input['reason'] = trim($input['reason']);
        }

        $this->replace($input);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }
}
