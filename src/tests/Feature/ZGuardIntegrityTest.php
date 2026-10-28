<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

test('database guard at end of suite: user count is 0, permission count remains exactly 98, and no test roles remain', function (): void {
    $usersCount = User::count();
    $permissionsCount = Permission::count();
    $testRolesCount = Role::where('name', 'LIKE', 'test_role_%')->count();

    expect($usersCount)->toBe(0)
        ->and($permissionsCount)->toBe(98)
        ->and($testRolesCount)->toBe(0);
});
