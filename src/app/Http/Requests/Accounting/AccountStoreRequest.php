<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Http\Requests\MasterData\BaseMasterDataRequest;
use Illuminate\Validation\Rule;

class AccountStoreRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('chart_of_accounts', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'normal_balance' => ['required', Rule::enum(NormalBalance::class)],
            'parent_id' => ['nullable', 'integer', Rule::exists('chart_of_accounts', 'id')->whereNull('deleted_at')],
            'is_postable' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
