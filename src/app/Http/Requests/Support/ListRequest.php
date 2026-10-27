<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

class ListRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'min:2', 'max:100'],
            'sort' => ['nullable', 'string', 'max:50'],
            'direction' => ['nullable', 'string', 'in:asc,desc,ASC,DESC'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'filter' => ['nullable', 'array'],
            'filter.*' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function search(): ?string
    {
        $val = $this->input('search');

        return is_string($val) && trim($val) !== '' ? trim($val) : null;
    }

    public function sort(): ?string
    {
        $val = $this->input('sort');

        return is_string($val) && trim($val) !== '' ? trim($val) : null;
    }

    public function direction(): string
    {
        return strtolower((string) $this->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
    }

    public function page(): int
    {
        return max(1, (int) $this->input('page', 1));
    }

    public function perPage(int $default = 25): int
    {
        return max(1, min(100, (int) $this->input('per_page', $default)));
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        $filters = $this->input('filter');

        return is_array($filters) ? $filters : [];
    }
}
