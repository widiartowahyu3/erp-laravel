<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use Illuminate\Validation\Rule;

final class AccountUpdateRequest extends AccountStoreRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $account = $this->route('account');
        $rules['code'] = ['required', 'string', 'max:20', Rule::unique('chart_of_accounts', 'code')->ignore(is_object($account) ? $account->getKey() : $account)];

        return $rules;
    }
}
