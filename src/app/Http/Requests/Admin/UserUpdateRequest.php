<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\MasterData\BaseMasterDataRequest;

/**
 * Roles must be present: a missing list would otherwise read as removing every role.
 */
final class UserUpdateRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'roles' => ['present', 'array'],
            'roles.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'email' => 'email',
            'roles' => 'peran',
        ];
    }
}
