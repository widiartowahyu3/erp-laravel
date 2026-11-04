<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Administration\AccessGuard;

beforeEach(function (): void {
    $this->guard = new AccessGuard;
});

function makeSuperAdmin(bool $active = true): User
{
    $user = User::factory()->create(['is_active' => $active]);
    $user->roles()->attach(Role::where('name', 'super_admin')->value('id'), ['created_at' => now()]);

    return $user;
}

function customRole(array $permissions, string $name = ''): Role
{
    $role = Role::create([
        'name' => $name !== '' ? $name : 'test_role_'.bin2hex(random_bytes(6)),
        'display_name' => 'Peran Uji',
        'is_system' => false,
    ]);
    $role->permissions()->attach(Permission::whereIn('name', $permissions)->pluck('id'));

    return $role;
}

function expectGuardRejects(callable $action, string $field): void
{
    try {
        $action();
        test()->fail('Expected BusinessRuleException was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBeIn([403, 409, 422])->and($e->field)->toBe($field);
    }
}

test('consistency: an action needs the view permission of the same resource', function (): void {
    expectGuardRejects(fn () => $this->guard->assertPermissionsConsistent(['products.create']), 'permissions');

    $this->guard->assertPermissionsConsistent(['products.view', 'products.create']);
    $this->guard->assertPermissionsConsistent([]);
    expect(true)->toBeTrue();
});

test('consistency: resources without a view permission are not forced to have one', function (): void {
    $this->guard->assertPermissionsConsistent(['reports.view_sales']);
    expect(true)->toBeTrue();
});

test('consistency: unknown permission names are rejected', function (): void {
    expectGuardRejects(fn () => $this->guard->assertPermissionsConsistent(['tidak_ada.view']), 'permissions');
});

test('ceiling: a user cannot grant a permission they do not hold; held permissions pass', function (): void {
    $admin = userWithPermissions(['roles.update', 'products.view']);

    expectGuardRejects(fn () => $this->guard->assertCanChangePermissions($admin, ['journals.post'], []), 'permissions');

    $this->guard->assertCanChangePermissions($admin, ['products.view'], []);
    expect(true)->toBeTrue();
});

test('ceiling: a user cannot revoke a permission they do not hold', function (): void {
    $admin = userWithPermissions(['roles.update', 'products.view']);

    expectGuardRejects(fn () => $this->guard->assertCanChangePermissions($admin, [], ['journals.post']), 'permissions');
});

test('ceiling: super admin may grant anything', function (): void {
    $this->guard->assertCanChangePermissions(makeSuperAdmin(), ['journals.post'], ['users.delete']);
    expect(true)->toBeTrue();
});

test('role assignment: only roles fully within the actor permissions; super_admin only by super admins', function (): void {
    $admin = userWithPermissions(['users.update', 'products.view', 'products.create']);
    $within = customRole(['products.view']);
    $beyond = customRole(['journals.view', 'journals.post']);
    $superAdminRole = Role::where('name', 'super_admin')->firstOrFail();

    $this->guard->assertCanAssignRoles($admin, [$within]);

    expectGuardRejects(fn () => $this->guard->assertCanAssignRoles($admin, [$beyond]), 'roles');
    expectGuardRejects(fn () => $this->guard->assertCanAssignRoles($admin, [$superAdminRole]), 'roles');

    $this->guard->assertCanAssignRoles(makeSuperAdmin(), [$superAdminRole, $beyond]);
});

test('managing users: a non super admin cannot touch a super admin', function (): void {
    $admin = userWithPermissions(['users.update']);

    expectGuardRejects(fn () => $this->guard->assertCanManageUser($admin, makeSuperAdmin()), 'form');
});

test('managing users: a target holding permissions beyond the actor is protected against takeover', function (): void {
    $admin = userWithPermissions(['users.update', 'products.view']);
    $finance = userWithPermissions(['journals.view', 'journals.post']);
    $staff = userWithPermissions(['products.view']);

    expectGuardRejects(fn () => $this->guard->assertCanManageUser($admin, $finance), 'form');

    $this->guard->assertCanManageUser($admin, $staff);
    $this->guard->assertCanManageUser(makeSuperAdmin(), $finance);
});

test('self protection: users cannot act on their own account or roles', function (): void {
    $user = makeSuperAdmin();

    expectGuardRejects(fn () => $this->guard->assertNotSelf($user, $user, 'roles'), 'roles');

    $this->guard->assertNotSelf($user, User::factory()->create(), 'roles');
    expect(true)->toBeTrue();
});

test('last super admin: the only active one is protected; a second active one lifts it', function (): void {
    $only = makeSuperAdmin();

    expectGuardRejects(fn () => $this->guard->assertNotLastActiveSuperAdmin($only), 'form');

    makeSuperAdmin(active: false);
    expectGuardRejects(fn () => $this->guard->assertNotLastActiveSuperAdmin($only), 'form');

    makeSuperAdmin();
    $this->guard->assertNotLastActiveSuperAdmin($only);
});

test('last super admin: other users are never blocked by the check', function (): void {
    makeSuperAdmin();

    $this->guard->assertNotLastActiveSuperAdmin(User::factory()->create());
    expect(true)->toBeTrue();
});

test('system roles are locked; custom roles are editable', function (): void {
    expectGuardRejects(fn () => $this->guard->assertRoleEditable(Role::where('name', 'viewer')->firstOrFail()), 'form');

    $this->guard->assertRoleEditable(customRole(['products.view']));
    expect(true)->toBeTrue();
});

test('a custom role in use cannot be deleted; an unused one can', function (): void {
    $used = customRole(['products.view']);
    User::factory()->create()->roles()->attach($used->id, ['created_at' => now()]);

    expectGuardRejects(fn () => $this->guard->assertRoleDeletable($used), 'form');
    expectGuardRejects(fn () => $this->guard->assertRoleDeletable(Role::where('name', 'viewer')->firstOrFail()), 'form');

    $this->guard->assertRoleDeletable(customRole(['products.view']));
});
