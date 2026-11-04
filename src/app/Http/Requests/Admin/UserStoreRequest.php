<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\MasterData\BaseMasterDataRequest;

/**
 * Password strength and email uniqueness are enforced by UserAdministrationService.
 */
final class UserStoreRequest extends BaseMasterDataRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed'],
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
            'password' => 'kata sandi',
            'roles' => 'peran',
        ];
    }
}
