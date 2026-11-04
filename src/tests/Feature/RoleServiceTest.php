<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Administration\RoleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->roles()->attach(Role::where('name', 'super_admin')->value('id'), ['created_at' => now()]);
    $this->actingAs($this->superAdmin);
    $this->service = new RoleService;
});

/**
 * @return list<string>
 */
function roleServicePermissions(Role $role): array
{
    return $role->permissions()->orderBy('name')->pluck('name')->all();
}

test('create makes a custom role with a generated slug, permissions and audit', function (): void {
    $role = $this->service->create($this->superAdmin, [
        'display_name' => 'Staf Master Data (Lihat Saja)',
        'description' => 'Hanya melihat',
        'permissions' => ['products.view', 'customers.view'],
    ]);

    expect($role->name)->toBe('staf_master_data_lihat_saja')
        ->and($role->is_system)->toBeFalse()
        ->and($role->created_by)->toBe($this->superAdmin->id)
        ->and(roleServicePermissions($role))->toBe(['customers.view', 'products.view']);

    $audit = DB::table('audit_logs')->where('event', 'role.created')->where('auditable_id', $role->id)->first();

    expect($audit)->not->toBeNull()->and($audit->new_values)->toContain('products.view');
});

test('slug gets a numeric suffix on collision, also against system roles', function (): void {
    $first = $this->service->create($this->superAdmin, ['display_name' => 'Gudang Cabang', 'permissions' => []]);
    $second = $this->service->create($this->superAdmin, ['display_name' => 'Gudang Cabang', 'permissions' => []]);
    $system = $this->service->create($this->superAdmin, ['display_name' => 'Viewer', 'permissions' => []]);

    expect($first->name)->toBe('gudang_cabang')
        ->and($second->name)->toBe('gudang_cabang_2')
        ->and($system->name)->toBe('viewer_2');
});

test('slug stays valid for names without usable letters', function (string $displayName): void {
    $role = $this->service->create($this->superAdmin, ['display_name' => $displayName, 'permissions' => []]);

    expect($role->name)->toMatch('/^[a-z][a-z0-9_]{2,49}$/');
})->with(['12345', 'A', '!!!', str_repeat('sangat panjang ', 6)]);

test('a role may have no permissions', function (): void {
    $role = $this->service->create($this->superAdmin, ['display_name' => 'Tanpa Izin', 'permissions' => []]);

    expect(roleServicePermissions($role))->toBe([]);
});

test('inconsistent permissions are rejected on the permissions field', function (): void {
    try {
        $this->service->create($this->superAdmin, ['display_name' => 'Salah', 'permissions' => ['products.create']]);
        test()->fail('Expected BusinessRuleException was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(422)->and($e->field)->toBe('permissions');
    }

    expect(Role::where('display_name', 'Salah')->exists())->toBeFalse();
});

test('update changes text and permissions and audits exactly what was added and removed', function (): void {
    $role = $this->service->create($this->superAdmin, ['display_name' => 'Awal', 'permissions' => ['products.view', 'customers.view']]);

    $updated = $this->service->update($this->superAdmin, $role, [
        'display_name' => 'Akhir',
        'description' => 'Baru',
        'permissions' => ['products.view', 'suppliers.view'],
    ]);

    expect($updated->display_name)->toBe('Akhir')
        ->and($updated->description)->toBe('Baru')
        ->and($updated->name)->toBe('awal')
        ->and(roleServicePermissions($updated))->toBe(['products.view', 'suppliers.view']);

    $audit = DB::table('audit_logs')->where('event', 'role.permissions_changed')->where('auditable_id', $role->id)->first();

    expect(json_decode((string) $audit->new_values, true))->toBe(['added' => ['suppliers.view'], 'removed' => ['customers.view']]);
});

test('system roles cannot be updated or deleted and stay untouched', function (): void {
    $viewer = Role::where('name', 'viewer')->firstOrFail();
    $before = roleServicePermissions($viewer);

    expect(fn () => $this->service->update($this->superAdmin, $viewer, ['display_name' => 'Diubah', 'permissions' => []]))
        ->toThrow(BusinessRuleException::class);
    expect(fn () => $this->service->delete($this->superAdmin, $viewer))->toThrow(BusinessRuleException::class);

    expect($viewer->fresh()->display_name)->not->toBe('Diubah')->and(roleServicePermissions($viewer))->toBe($before);
});

test('duplicate copies permissions into an editable custom role', function (): void {
    $viewer = Role::where('name', 'viewer')->firstOrFail();

    $copy = $this->service->duplicate($this->superAdmin, $viewer);

    expect($copy->is_system)->toBeFalse()
        ->and($copy->id)->not->toBe($viewer->id)
        ->and($copy->display_name)->toBe('Salinan '.$viewer->display_name)
        ->and(roleServicePermissions($copy))->toBe(roleServicePermissions($viewer));

    $this->service->update($this->superAdmin, $copy, ['display_name' => 'Ubahan', 'permissions' => ['products.view']]);

    expect(roleServicePermissions($viewer))->not->toBe(['products.view']);
});

test('delete removes an unused custom role with audit; a role in use is refused', function (): void {
    $unused = $this->service->create($this->superAdmin, ['display_name' => 'Hapus Saya', 'permissions' => ['products.view']]);
    $id = $unused->id;

    $this->service->delete($this->superAdmin, $unused);

    expect(Role::find($id))->toBeNull()
        ->and(DB::table('permission_role')->where('role_id', $id)->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('event', 'role.deleted')->where('auditable_id', $id)->exists())->toBeTrue();

    $used = $this->service->create($this->superAdmin, ['display_name' => 'Dipakai', 'permissions' => []]);
    User::factory()->create()->roles()->attach($used->id, ['created_at' => now()]);

    expect(fn () => $this->service->delete($this->superAdmin, $used))->toThrow(BusinessRuleException::class);
    expect(Role::find($used->id))->not->toBeNull();
});

test('a non super admin cannot grant, revoke, duplicate or delete beyond their own permissions', function (): void {
    $admin = userWithPermissions(['roles.update', 'roles.create', 'roles.delete', 'products.view']);
    $beyond = $this->service->create($this->superAdmin, ['display_name' => 'Keuangan Cabang', 'permissions' => ['journals.view', 'journals.post', 'products.view']]);

    expect(fn () => $this->service->create($admin, ['display_name' => 'Naik', 'permissions' => ['journals.view']]))
        ->toThrow(BusinessRuleException::class);
    expect(fn () => $this->service->update($admin, $beyond, ['display_name' => 'Keuangan Cabang', 'permissions' => ['products.view']]))
        ->toThrow(BusinessRuleException::class);
    expect(fn () => $this->service->duplicate($admin, $beyond))->toThrow(BusinessRuleException::class);
    expect(fn () => $this->service->delete($admin, $beyond))->toThrow(BusinessRuleException::class);

    // Positive control: within the ceiling, and permissions outside it may stay untouched
    expect($this->service->create($admin, ['display_name' => 'Boleh', 'permissions' => ['products.view']])->name)->toBe('boleh');

    $renamed = $this->service->update($admin, $beyond, ['display_name' => 'Nama Baru', 'permissions' => ['journals.view', 'journals.post', 'products.view']]);
    expect($renamed->display_name)->toBe('Nama Baru');
});

test('audit failure rolls the role back', function (): void {
    $fake = new User;
    $fake->id = 999999999;
    Auth::setUser($fake);
    $thrown = false;

    try {
        $this->service->create($this->superAdmin, ['display_name' => 'Should Rollback', 'permissions' => ['products.view']]);
    } catch (\Throwable) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue()->and(Role::where('display_name', 'Should Rollback')->exists())->toBeFalse();
});

test('permissions sync leaves custom roles and their relations intact', function (): void {
    $role = $this->service->create($this->superAdmin, ['display_name' => 'Kustom Tetap', 'permissions' => ['products.view', 'customers.view']]);
    $holder = User::factory()->create();
    $holder->roles()->attach($role->id, ['created_at' => now()]);

    $this->artisan('erp:permissions:sync')->assertSuccessful();

    $fresh = Role::findOrFail($role->id);

    expect($fresh->display_name)->toBe('Kustom Tetap')
        ->and($fresh->is_system)->toBeFalse()
        ->and(roleServicePermissions($fresh))->toBe(['customers.view', 'products.view'])
        ->and($fresh->users()->whereKey($holder->id)->exists())->toBeTrue()
        ->and(Permission::where('name', 'products.view')->exists())->toBeTrue();
});
