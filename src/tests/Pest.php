<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

pest()->afterEach(function (): void {
    if (! app()->bound('db') || Model::getConnectionResolver() === null) {
        return;
    }

    try {
        // 1. Clean up temporary test roles and their pivot relations
        $testRoles = Role::where('name', 'LIKE', 'test_role_%')->get();
        foreach ($testRoles as $role) {
            $role->permissions()->detach();
            $role->users()->detach();
            $role->delete();
        }

        // 2. Clean up non-seeded permissions that may have been created during tests
        $standardNames = array_column(PermissionSeeder::permissions(), 'name');
        if (! empty($standardNames)) {
            Permission::whereNotIn('name', $standardNames)->delete();
        }
    } catch (Throwable) {
        // Silently skip if DB connection is unavailable in stateless/arch tests
    }
});

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
