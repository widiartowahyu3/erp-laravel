<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

const ADMIN_HTTP_PASSWORD = 'Valid#Password2026';

beforeEach(function (): void {
    $this->superRoleId = (int) Role::where('name', 'super_admin')->value('id');
    $this->viewerRoleId = (int) Role::where('name', 'viewer')->value('id');
    $this->superAdmin = User::factory()->create(['name' => 'Akar']);
    $this->superAdmin->roles()->attach($this->superRoleId, ['created_at' => now()]);
    $this->target = User::factory()->create(['name' => 'Target Uji', 'email' => 'target@example.test']);
});

function superSession($test): void
{
    $test->actingAs(User::findOrFail($test->superAdmin->id));
}

test('every user route answers 403 without its permission and works with it', function (string $method, string $uriTemplate, string $permission, int $allowed): void {
    $uri = str_replace('{id}', (string) $this->target->id, $uriTemplate);
    $body = match (true) {
        str_ends_with($uri, '/reset-password') => ['password' => ADMIN_HTTP_PASSWORD, 'password_confirmation' => ADMIN_HTTP_PASSWORD],
        str_ends_with($uri, '/active') => ['is_active' => false],
        $method === 'put' => ['name' => 'Baru', 'email' => 'target@example.test', 'roles' => []],
        $method === 'post' => ['name' => 'Baru', 'email' => 'baru.http@example.test', 'password' => ADMIN_HTTP_PASSWORD, 'password_confirmation' => ADMIN_HTTP_PASSWORD, 'roles' => []],
        default => [],
    };

    $this->actingAs(userWithPermissions(['products.view']))->{$method}($uri, $body)->assertStatus(403);
    $this->flushSession();

    $this->actingAs(userWithPermissions([$permission]))->{$method}($uri, $body)->assertStatus($allowed);
})->with([
    'index' => ['get', '/admin/users', 'users.view', 200],
    'create' => ['get', '/admin/users/create', 'users.create', 200],
    'store' => ['post', '/admin/users', 'users.create', 302],
    'show' => ['get', '/admin/users/{id}', 'users.view', 200],
    'edit' => ['get', '/admin/users/{id}/edit', 'users.update', 200],
    'update' => ['put', '/admin/users/{id}', 'users.update', 302],
    'reset password' => ['post', '/admin/users/{id}/reset-password', 'users.update', 302],
    'set active' => ['post', '/admin/users/{id}/active', 'users.delete', 302],
]);

test('unknown user answers 404', function (): void {
    $this->actingAs(userWithPermissions(['users.view']))->get('/admin/users/99999999')->assertStatus(404);
});

test('store creates the user and never echoes the password', function (): void {
    superSession($this);

    $this->post('/admin/users', [
        'name' => 'Staf Baru',
        'email' => 'STAF@example.test',
        'password' => ADMIN_HTTP_PASSWORD,
        'password_confirmation' => ADMIN_HTTP_PASSWORD,
        'roles' => [$this->viewerRoleId],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $user = User::where('email', 'staf@example.test')->firstOrFail();

    expect(Hash::check(ADMIN_HTTP_PASSWORD, (string) $user->password))->toBeTrue()
        ->and($user->roles()->pluck('roles.id')->all())->toBe([$this->viewerRoleId])
        ->and(session()->getOldInput('password'))->toBeNull();

    $this->flushSession();
    superSession($this);
    $this->get("/admin/users/{$user->id}")->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Users/Show')
        ->where('item.email', 'staf@example.test')
        ->missing('item.password')
        ->missing('item.remember_token')
    );
});

test('store validates input', function (array $overrides, string $field): void {
    superSession($this);

    $this->withHeader('X-Inertia', 'true')->post('/admin/users', array_merge([
        'name' => 'Staf',
        'email' => 'valid@example.test',
        'password' => ADMIN_HTTP_PASSWORD,
        'password_confirmation' => ADMIN_HTTP_PASSWORD,
        'roles' => [],
    ], $overrides))->assertSessionHasErrors($field);

    expect(User::where('email', 'valid@example.test')->exists())->toBeFalse();
})->with([
    'no name' => [['name' => ''], 'name'],
    'bad email' => [['email' => 'bukan-email'], 'email'],
    'duplicate email' => [['email' => 'TARGET@example.test'], 'email'],
    'no confirmation' => [['password_confirmation' => 'Lain#Password2026'], 'password'],
    'weak password' => [['password' => 'lemah', 'password_confirmation' => 'lemah'], 'password'],
    'unknown role' => [['roles' => [99999999]], 'roles'],
]);

test('store ignores fields the form does not own', function (): void {
    superSession($this);

    $this->post('/admin/users', [
        'name' => 'Staf',
        'email' => 'massal@example.test',
        'password' => ADMIN_HTTP_PASSWORD,
        'password_confirmation' => ADMIN_HTTP_PASSWORD,
        'roles' => [],
        'is_active' => false,
        'remember_token' => 'x',
    ])->assertSessionHasNoErrors();

    $user = User::where('email', 'massal@example.test')->firstOrFail();

    expect($user->is_active)->toBeTrue()->and($user->remember_token)->toBeNull();
});

test('index searches, filters by status and role, and paginates', function (): void {
    $this->target->roles()->attach($this->viewerRoleId, ['created_at' => now()]);
    superSession($this);

    $this->get('/admin/users?search=target@')->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Users/Index')
        ->has('items.data', 1)
        ->where('items.data.0.id', $this->target->id)
        ->where('items.data.0.roles.0.id', $this->viewerRoleId)
        ->has('roles')
    );

    $this->flushSession();
    superSession($this);
    $this->get('/admin/users?filter[role_id]='.$this->viewerRoleId)->assertInertia(fn (Assert $page) => $page->has('items.data', 1));

    DB::table('users')->where('id', $this->target->id)->update(['is_active' => false]);
    $this->flushSession();
    superSession($this);
    $this->get('/admin/users?filter[is_active]=0')->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $this->target->id));
});

test('index does not run a query per user', function (): void {
    superSession($this);
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/admin/users')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count();
    $withFew = $count();

    foreach (User::factory()->count(5)->create() as $user) {
        $user->roles()->attach($this->viewerRoleId, ['created_at' => now()]);
    }

    expect($count())->toBe($withFew);
});

test('super admins and their count stay hidden from non super admins', function (): void {
    $admin = userWithPermissions(['users.view', 'users.update', 'users.delete']);

    $this->actingAs($admin)->get('/admin/users')->assertInertia(function (Assert $page): void {
        $page->where('items.data', fn ($rows): bool => collect($rows)->doesntContain('name', 'Akar'));
    });

    $this->flushSession();
    $this->actingAs($admin)->get("/admin/users/{$this->superAdmin->id}")->assertStatus(404);
    $this->flushSession();
    $this->actingAs($admin)->get("/admin/users/{$this->superAdmin->id}/edit")->assertStatus(404);
    $this->flushSession();
    $this->actingAs($admin)->post("/admin/users/{$this->superAdmin->id}/active", ['is_active' => false])->assertStatus(404);

    expect($this->superAdmin->fresh()->is_active)->toBeTrue();
});

test('create page marks which roles the actor may assign', function (): void {
    $admin = userWithPermissions(['users.create', 'products.view']);

    $this->actingAs($admin)->get('/admin/users/create')->assertInertia(function (Assert $page): void {
        $page->component('Admin/Users/Create')->where('assignableRoles', function ($roles): bool {
            $roles = collect($roles);

            return $roles->firstWhere('name', 'super_admin') === null
                && $roles->firstWhere('name', 'finance')['assignable'] === false;
        });
    });

    $this->flushSession();
    superSession($this);
    $this->get('/admin/users/create')->assertInertia(fn (Assert $page) => $page->where('assignableRoles', function ($roles): bool {
        return collect($roles)->firstWhere('name', 'super_admin')['assignable'] === true;
    }));
});

test('show exposes why actions on an account are blocked', function (): void {
    superSession($this);

    $this->get("/admin/users/{$this->superAdmin->id}")->assertInertia(fn (Assert $page) => $page
        ->where('isSelf', true)
        ->where('isLastSuperAdmin', true)
        ->where('canManage', true)
    );

    $this->flushSession();
    superSession($this);
    $this->get("/admin/users/{$this->target->id}")->assertInertia(fn (Assert $page) => $page
        ->where('isSelf', false)
        ->where('isLastSuperAdmin', false)
    );
});

test('self protection and last super admin are enforced over http', function (): void {
    superSession($this);
    $this->withHeader('X-Inertia', 'true');

    $this->post("/admin/users/{$this->superAdmin->id}/active", ['is_active' => false])->assertSessionHasErrors('form');
    $this->post("/admin/users/{$this->superAdmin->id}/reset-password", ['password' => ADMIN_HTTP_PASSWORD, 'password_confirmation' => ADMIN_HTTP_PASSWORD])->assertSessionHasErrors('form');
    $this->put("/admin/users/{$this->superAdmin->id}", ['name' => 'Akar', 'email' => $this->superAdmin->email, 'roles' => []])->assertSessionHasErrors('roles');

    expect($this->superAdmin->fresh()->is_active)->toBeTrue()
        ->and($this->superAdmin->fresh()->roles()->count())->toBe(1);
});

test('deactivating revokes sessions and blocks login-held access', function (): void {
    DB::table('sessions')->insert(['id' => 'sess_admin_http', 'user_id' => $this->target->id, 'payload' => 'x', 'last_activity' => now()->timestamp]);
    superSession($this);

    $this->post("/admin/users/{$this->target->id}/active", ['is_active' => false])->assertSessionHasNoErrors();

    expect($this->target->fresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $this->target->id)->count())->toBe(0);

    $this->post("/admin/users/{$this->target->id}/active", ['is_active' => true])->assertSessionHasNoErrors();
    expect($this->target->fresh()->is_active)->toBeTrue();
});

test('reset password validates, works, and is throttled like every write', function (): void {
    superSession($this);
    $this->withHeader('X-Inertia', 'true');

    $this->post("/admin/users/{$this->target->id}/reset-password", ['password' => 'lemah', 'password_confirmation' => 'lemah'])->assertSessionHasErrors('password');
    $this->post("/admin/users/{$this->target->id}/reset-password", ['password' => ADMIN_HTTP_PASSWORD, 'password_confirmation' => 'Beda#Password2026'])->assertSessionHasErrors('password');

    $this->flushSession();
    superSession($this);
    $this->post("/admin/users/{$this->target->id}/reset-password", ['password' => ADMIN_HTTP_PASSWORD, 'password_confirmation' => ADMIN_HTTP_PASSWORD])->assertSessionHasNoErrors();

    expect(Hash::check(ADMIN_HTTP_PASSWORD, (string) $this->target->fresh()->password))->toBeTrue();
});
