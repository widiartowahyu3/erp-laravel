<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Helper to create a user with a temporary role possessing the specified permissions.
 *
 * @param  array<int, string>  $names
 */
function userWithPermissions(array $names): User
{
    $user = User::factory()->create();

    $roleName = 'test_role_'.bin2hex(random_bytes(6));
    $role = Role::create([
        'name' => $roleName,
        'display_name' => 'Test Role '.$roleName,
        'description' => 'Temporary test role for permissions',
        'is_system' => false,
    ]);

    $permissionIds = [];
    foreach ($names as $name) {
        $module = explode('.', $name)[0] ?? 'custom';
        $permission = Permission::firstOrCreate(
            ['name' => $name],
            [
                'module' => $module,
                'display_name' => $name,
            ]
        );
        $permissionIds[] = $permission->id;
    }

    if (! empty($permissionIds)) {
        $role->permissions()->attach($permissionIds);
    }

    $user->roles()->attach($role->id);

    return $user;
}

/**
 * Helper to create a user attached to a specific role.
 */
function userWithRole(string $role): User
{
    $roleModel = Role::where('name', $role)->first();

    if (! $roleModel) {
        $roleModel = Role::create([
            'name' => $role,
            'display_name' => ucfirst(str_replace('_', ' ', $role)),
            'description' => 'Test role '.$role,
            'is_system' => false,
        ]);
    }

    $user = User::factory()->create();
    $user->roles()->attach($roleModel->id);

    return $user;
}
