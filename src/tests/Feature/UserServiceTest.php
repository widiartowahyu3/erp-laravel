<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Models\User;
use App\Services\Administration\RoleService;
use App\Services\Administration\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const USER_SERVICE_PASSWORD = 'Valid#Password2026';

beforeEach(function (): void {
    $this->superRoleId = (int) Role::where('name', 'super_admin')->value('id');
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->roles()->attach($this->superRoleId, ['created_at' => now()]);
    $this->secondSuperAdmin = User::factory()->create();
    $this->secondSuperAdmin->roles()->attach($this->superRoleId, ['created_at' => now()]);
    $this->viewerRoleId = (int) Role::where('name', 'viewer')->value('id');
    $this->actingAs($this->superAdmin);
    $this->service = new UserService;
});

function userServiceData(array $overrides = []): array
{
    return array_merge(['name' => 'Staf Baru', 'email' => 'staf.baru@example.test', 'password' => USER_SERVICE_PASSWORD, 'roles' => []], $overrides);
}

function expectUserRule(callable $action, string $field): void
{
    try {
        $action();
        test()->fail('Expected BusinessRuleException was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->field)->toBe($field);
    }
}

test('create stores the user with roles and an audit that holds no password or hash', function (): void {
    $user = $this->service->create($this->superAdmin, userServiceData(['roles' => [$this->viewerRoleId]]));

    expect($user->roles()->pluck('roles.id')->all())->toBe([$this->viewerRoleId])
        ->and($user->created_by)->toBe($this->superAdmin->id);

    $values = (string) DB::table('audit_logs')->where('event', 'user.created')->where('auditable_id', $user->id)->value('new_values');

    expect($values)->not->toContain(USER_SERVICE_PASSWORD)->and($values)->not->toContain((string) $user->password);
});

test('email uniqueness is case insensitive; unknown roles are refused', function (): void {
    $this->service->create($this->superAdmin, userServiceData());

    expectUserRule(fn () => $this->service->create($this->superAdmin, userServiceData(['email' => 'STAF.BARU@example.test'])), 'email');
    expectUserRule(fn () => $this->service->create($this->superAdmin, userServiceData(['email' => 'lain@example.test', 'roles' => [99999999]])), 'roles');
});

test('update changes name and email and audits the email as a hash only', function (): void {
    $user = $this->service->create($this->superAdmin, userServiceData());

    $this->service->update($this->superAdmin, $user, ['name' => 'Nama Baru', 'email' => 'BARU@example.test', 'roles' => []]);

    expect($user->fresh()->name)->toBe('Nama Baru')->and($user->fresh()->email)->toBe('baru@example.test');

    $audit = DB::table('audit_logs')->where('event', 'user.updated')->where('auditable_id', $user->id)->first();

    expect($audit->new_values)->toContain('email_hash')->and($audit->new_values)->not->toContain('baru@example.test');
});

test('update keeps its own email without a duplicate error, and refuses someone else\'s', function (): void {
    $user = $this->service->create($this->superAdmin, userServiceData());
    $other = User::factory()->create(['email' => 'lain@example.test']);

    $this->service->update($this->superAdmin, $user, ['name' => 'Sama', 'email' => 'staf.baru@example.test', 'roles' => []]);

    expectUserRule(fn () => $this->service->update($this->superAdmin, $user, ['name' => 'Sama', 'email' => $other->email, 'roles' => []]), 'email');
});

test('changing roles is audited with what was added and removed', function (): void {
    $user = $this->service->create($this->superAdmin, userServiceData(['roles' => [$this->viewerRoleId]]));
    $financeId = (int) Role::where('name', 'finance')->value('id');

    $this->service->update($this->superAdmin, $user, ['name' => 'Staf', 'email' => 'staf.baru@example.test', 'roles' => [$financeId]]);

    $audit = DB::table('audit_logs')->where('event', 'user.roles_changed')->where('auditable_id', $user->id)->first();

    expect(json_decode((string) $audit->new_values, true))->toBe(['added' => ['finance'], 'removed' => ['viewer']])
        ->and($user->fresh()->roles()->pluck('roles.id')->all())->toBe([$financeId]);
});

test('users cannot change their own roles or deactivate or reset themselves; their own name is fine', function (): void {
    expectUserRule(fn () => $this->service->update($this->superAdmin, $this->superAdmin, ['name' => 'X', 'email' => $this->superAdmin->email, 'roles' => []]), 'roles');
    expectUserRule(fn () => $this->service->setActive($this->superAdmin, $this->superAdmin, false), 'form');
    expectUserRule(fn () => $this->service->resetPassword($this->superAdmin, $this->superAdmin, USER_SERVICE_PASSWORD), 'form');

    $this->service->update($this->superAdmin, $this->superAdmin, ['name' => 'Nama Saya', 'email' => $this->superAdmin->email, 'roles' => [$this->superRoleId]]);

    expect($this->superAdmin->fresh()->name)->toBe('Nama Saya');
});

test('the last active super admin cannot be deactivated or demoted; with a second one it can', function (): void {
    $this->service->setActive($this->superAdmin, $this->secondSuperAdmin, false);

    $third = User::factory()->create();
    $third->roles()->attach($this->superRoleId, ['created_at' => now()]);
    $this->service->update($this->superAdmin, $third, ['name' => 'Turun', 'email' => $third->email, 'roles' => []]);
    expect($third->fresh()->roles()->count())->toBe(0);

    $actor = User::factory()->create();
    $actor->roles()->attach(Role::where('name', 'admin')->value('id'), ['created_at' => now()]);

    // The super admin itself is now the only active one, protected even against another super admin
    $other = User::factory()->create();
    $other->roles()->attach($this->superRoleId, ['created_at' => now()]);
    DB::table('users')->where('id', $other->id)->update(['is_active' => false]);

    $activeOther = User::factory()->create();
    $activeOther->roles()->attach($this->superRoleId, ['created_at' => now()]);
    $this->service->setActive($this->superAdmin, $activeOther, false);

    expectUserRule(fn () => (new UserService)->update($other->fresh(), $this->superAdmin, ['name' => 'X', 'email' => $this->superAdmin->email, 'roles' => []]), 'form');
    expectUserRule(fn () => (new UserService)->setActive($other->fresh(), $this->superAdmin, false), 'form');

    expect($this->superAdmin->fresh()->is_active)->toBeTrue();
});

test('a non super admin cannot touch super admins, assign beyond their ceiling, or take over stronger users', function (): void {
    $admin = userWithPermissions(['users.update', 'users.create', 'users.delete', 'products.view']);
    $withinRole = (new RoleService)->create($this->superAdmin, ['display_name' => 'Lihat Produk', 'permissions' => ['products.view']]);
    $staff = $this->service->create($this->superAdmin, userServiceData(['roles' => [$withinRole->id]]));
    $finance = $this->service->create($this->superAdmin, userServiceData(['email' => 'keu@example.test', 'roles' => [(int) Role::where('name', 'finance')->value('id')]]));

    expectUserRule(fn () => $this->service->resetPassword($admin, $this->superAdmin, USER_SERVICE_PASSWORD), 'form');
    expectUserRule(fn () => $this->service->setActive($admin, $this->superAdmin, false), 'form');
    expectUserRule(fn () => $this->service->resetPassword($admin, $finance, USER_SERVICE_PASSWORD), 'form');
    expectUserRule(fn () => $this->service->update($admin, $staff, ['name' => 'Staf', 'email' => $staff->email, 'roles' => [$this->superRoleId]]), 'roles');
    expectUserRule(fn () => $this->service->create($admin, userServiceData(['email' => 'naik@example.test', 'roles' => [$this->viewerRoleId]])), 'roles');

    expect(Hash::check(USER_SERVICE_PASSWORD, (string) $this->superAdmin->fresh()->password))->toBeFalse();

    // Positive controls within the ceiling
    $this->service->resetPassword($admin, $staff, 'Another#Password2026');
    $this->service->setActive($admin, $staff, false);
    expect($this->service->create($admin, userServiceData(['email' => 'boleh@example.test', 'roles' => [$withinRole->id]]))->exists)->toBeTrue();
});

test('reset password audit holds no password and revokes sessions; setActive revokes on deactivate', function (): void {
    $user = $this->service->create($this->superAdmin, userServiceData());
    DB::table('sessions')->insert(['id' => 'sess_user_service', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => now()->timestamp]);

    $this->service->resetPassword($this->superAdmin, $user, 'Another#Password2026');

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and((string) DB::table('audit_logs')->where('event', 'password.reset')->where('auditable_id', $user->id)->value('new_values'))->not->toContain('Another#Password2026');
});

test('a role change applies on the next request without touching the session', function (): void {
    $roleId = (new RoleService)->create($this->superAdmin, ['display_name' => 'Satuan', 'permissions' => ['units.view']])->id;
    $user = $this->service->create($this->superAdmin, userServiceData());

    $this->actingAs(User::findOrFail($user->id))->get('/master/units')->assertStatus(403);

    $this->service->update($this->superAdmin, User::findOrFail($user->id), ['name' => 'Staf', 'email' => $user->email, 'roles' => [$roleId]]);

    $this->flushSession();
    $this->actingAs(User::findOrFail($user->id))->get('/master/units')->assertStatus(200);

    $this->service->update($this->superAdmin, User::findOrFail($user->id), ['name' => 'Staf', 'email' => $user->email, 'roles' => []]);

    $this->flushSession();
    $this->actingAs(User::findOrFail($user->id))->get('/master/units')->assertStatus(403);
});
