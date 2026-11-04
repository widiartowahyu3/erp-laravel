<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;

final class JournalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Blank amounts mean the other side of the line was filled in.
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();

        if (isset($input['lines']) && is_array($input['lines'])) {
            foreach ($input['lines'] as $index => $line) {
                if (is_array($line)) {
                    foreach (['debit', 'credit'] as $side) {
                        if (! isset($line[$side]) || (is_string($line[$side]) && trim($line[$side]) === '')) {
                            $input['lines'][$index][$side] = '0';
                        }
                    }
                }
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
            'journal_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['required', new DecimalString(16, 2, '0')],
            'lines.*.credit' => ['required', new DecimalString(16, 2, '0')],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
