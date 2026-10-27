<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;

test('database guard at end of suite: permission count remains exactly 98 and no test roles remain', function (): void {
    $permissionsCount = Permission::count();
    $testRolesCount = Role::where('name', 'LIKE', 'test_role_%')->count();

    expect($permissionsCount)->toBe(98)
        ->and($testRolesCount)->toBe(0);
});
