<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Administration\RoleService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config(['inertia.testing.ensure_pages_exist' => false]);
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->roles()->attach(Role::where('name', 'super_admin')->value('id'), ['created_at' => now()]);
    $this->custom = (new RoleService)->create($this->superAdmin, ['display_name' => 'Peran Kustom', 'permissions' => ['products.view']]);
    $this->payload = ['display_name' => 'Peran Baru', 'description' => null, 'permissions' => ['products.view']];
});

test('every role route answers 403 without its permission and works with it', function (string $method, string $uriTemplate, string $permission, int $allowed): void {
    $uri = str_replace('{id}', (string) $this->custom->id, $uriTemplate);
    $body = in_array($method, ['post', 'put'], true) && ! str_ends_with($uri, '/duplicate') ? $this->payload : [];
    $permissions = [$permission, 'products.view'];

    $this->actingAs(userWithPermissions(['products.view']))->{$method}($uri, $body)->assertStatus(403);
    $this->flushSession();

    $this->actingAs(userWithPermissions($permissions))->{$method}($uri, $body)->assertStatus($allowed);
})->with([
    'index' => ['get', '/admin/roles', 'roles.view', 200],
    'create' => ['get', '/admin/roles/create', 'roles.create', 200],
    'store' => ['post', '/admin/roles', 'roles.create', 302],
    'show' => ['get', '/admin/roles/{id}', 'roles.view', 200],
    'edit' => ['get', '/admin/roles/{id}/edit', 'roles.update', 200],
    'update' => ['put', '/admin/roles/{id}', 'roles.update', 302],
    'destroy' => ['delete', '/admin/roles/{id}', 'roles.delete', 302],
    'duplicate' => ['post', '/admin/roles/{id}/duplicate', 'roles.create', 302],
]);

test('unknown role answers 404', function (): void {
    $this->actingAs(userWithPermissions(['roles.view']))->get('/admin/roles/99999999')->assertStatus(404);
});

test('index lists roles with counts; the super admin user count is hidden from others', function (): void {
    $this->actingAs(User::findOrFail($this->superAdmin->id))->get('/admin/roles')->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Roles/Index')
        ->where('items.data', function ($rows): bool {
            $super = collect($rows)->firstWhere('name', 'super_admin');
            $custom = collect($rows)->firstWhere('name', 'peran_kustom');

            return $super['usersCount'] >= 1 && $super['isSystem'] === true
                && $custom['usersCount'] === 0 && $custom['permissionsCount'] === 1 && $custom['isSystem'] === false;
        })
    );

    $this->flushSession();

    $this->actingAs(userWithPermissions(['roles.view']))->get('/admin/roles')->assertInertia(fn (Assert $page) => $page
        ->where('items.data', fn ($rows): bool => collect($rows)->firstWhere('name', 'super_admin')['usersCount'] === null)
    );
});

test('create page groups the matrix by module, resource and action, with the actor ceiling', function (): void {
    $this->actingAs(userWithPermissions(['roles.create', 'products.view']))->get('/admin/roles/create')->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Roles/Create')
        ->where('grantablePermissions', fn ($names): bool => collect($names)->contains('products.view') && ! collect($names)->contains('journals.post'))
        ->where('permissionMatrix', function ($modules): bool {
            $master = collect($modules)->firstWhere('key', 'master_data');
            $products = collect($master['resources'])->firstWhere('key', 'products');
            $view = collect($products['actions'])->firstWhere('action', 'view');

            return $master['label'] === 'Data Master'
                && $products['label'] === 'Master Produk'
                && $view['name'] === 'products.view'
                && $view['label'] === 'Lihat';
        })
    );
});

test('store creates the role and rejects inconsistent or missing permissions', function (): void {
    $this->actingAs(User::findOrFail($this->superAdmin->id));
    $this->withHeader('X-Inertia', 'true');

    $this->post('/admin/roles', ['display_name' => 'Salah', 'permissions' => ['products.create']])->assertSessionHasErrors('permissions');
    $this->post('/admin/roles', ['display_name' => '', 'permissions' => []])->assertSessionHasErrors('display_name');
    $this->post('/admin/roles', ['display_name' => 'Salah Dua', 'permissions' => 'products.view'])->assertSessionHasErrors('permissions');

    $this->flushSession();
    $this->actingAs(User::findOrFail($this->superAdmin->id));
    $this->post('/admin/roles', ['display_name' => 'Peran Kosong', 'permissions' => []])->assertSessionHasNoErrors();

    expect(Role::where('name', 'peran_kosong')->exists())->toBeTrue();
});

test('store ignores is_system and name sent by the client', function (): void {
    $this->actingAs(User::findOrFail($this->superAdmin->id));

    $this->post('/admin/roles', ['display_name' => 'Bukan Sistem', 'permissions' => [], 'is_system' => true, 'name' => 'super_admin'])->assertSessionHasNoErrors();

    $role = Role::where('display_name', 'Bukan Sistem')->firstOrFail();

    expect($role->is_system)->toBeFalse()->and($role->name)->toBe('bukan_sistem');
});

test('the ceiling and system role lock are enforced over http', function (): void {
    $admin = userWithPermissions(['roles.create', 'roles.update', 'roles.delete', 'products.view']);
    $viewer = Role::where('name', 'viewer')->firstOrFail();
    $this->withHeader('X-Inertia', 'true');

    $this->actingAs($admin)->post('/admin/roles', ['display_name' => 'Naik', 'permissions' => ['journals.view']])->assertSessionHasErrors('permissions');
    $this->actingAs($admin)->put("/admin/roles/{$viewer->id}", $this->payload)->assertSessionHasErrors('form');

    expect(Role::where('display_name', 'Naik')->exists())->toBeFalse()
        ->and($viewer->fresh()->display_name)->not->toBe('Peran Baru');
});

test('editing a system role redirects to its read-only page', function (): void {
    $viewer = Role::where('name', 'viewer')->firstOrFail();

    $this->actingAs(User::findOrFail($this->superAdmin->id))->get("/admin/roles/{$viewer->id}/edit")->assertRedirect("/admin/roles/{$viewer->id}");
});

test('show exposes selected permissions and whether the role is locked', function (): void {
    $this->actingAs(User::findOrFail($this->superAdmin->id))->get("/admin/roles/{$this->custom->id}")->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Roles/Show')
        ->where('item.display_name', 'Peran Kustom')
        ->where('selectedPermissions', ['products.view'])
        ->where('usersCount', 0)
        ->has('permissionMatrix')
    );
});

test('duplicate opens the new editable copy', function (): void {
    $viewer = Role::where('name', 'viewer')->firstOrFail();

    $response = $this->actingAs(User::findOrFail($this->superAdmin->id))->post("/admin/roles/{$viewer->id}/duplicate");

    $copy = Role::where('display_name', 'Salinan '.$viewer->display_name)->firstOrFail();
    $response->assertRedirect("/admin/roles/{$copy->id}/edit");

    expect($copy->is_system)->toBeFalse();
});

test('delete removes an unused role and reports a role in use', function (): void {
    $this->actingAs(User::findOrFail($this->superAdmin->id));

    User::factory()->create()->roles()->attach($this->custom->id, ['created_at' => now()]);
    $this->delete("/admin/roles/{$this->custom->id}")->assertSessionHas('error');
    expect(Role::find($this->custom->id))->not->toBeNull();

    $unused = (new RoleService)->create($this->superAdmin, ['display_name' => 'Tak Terpakai', 'permissions' => []]);
    $this->delete("/admin/roles/{$unused->id}")->assertSessionHas('success');
    expect(Role::find($unused->id))->toBeNull();
});
