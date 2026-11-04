<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route;

test('all admin routes are protected by authentication, specific can middleware and write throttling', function (): void {
    $adminRoutes = [];

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'admin/')) {
            $adminRoutes[] = $route;
        }
    }

    expect($adminRoutes)->toHaveCount(17);

    foreach ($adminRoutes as $route) {
        $uri = $route->uri();
        $middleware = $route->gatherMiddleware();

        expect(in_array('auth', $middleware, true))->toBeTrue("Route {$uri} must have 'auth' middleware.");

        $can = array_values(array_filter($middleware, fn ($m): bool => is_string($m) && str_starts_with($m, 'can:')));
        expect($can)->toHaveCount(1, "Route {$uri} must have one 'can:' middleware.");
        expect($can[0])->toMatch('/^can:(users\.(view|create|update|delete)|roles\.(view|create|update|delete)|audit_logs\.view)$/');

        if (count(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) > 0) {
            expect(in_array('throttle:erp-write', $middleware, true))->toBeTrue("Write route {$uri} must be rate limited.");
        }
    }
});

test('guests are redirected to login on admin pages', function (string $uri): void {
    $this->get($uri)->assertRedirect('/login');
})->with(['/admin/users', '/admin/users/create', '/admin/roles', '/admin/roles/create', '/admin/audit-logs']);

test('operational system roles get 403 on every administration route', function (string $roleName): void {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', $roleName)->value('id'), ['created_at' => now()]);
    $targetUser = User::factory()->create();
    $targetRole = Role::where('name', 'viewer')->value('id');

    $routes = [
        ['get', '/admin/users'],
        ['get', '/admin/users/create'],
        ['post', '/admin/users'],
        ['get', "/admin/users/{$targetUser->id}"],
        ['get', "/admin/users/{$targetUser->id}/edit"],
        ['put', "/admin/users/{$targetUser->id}"],
        ['post', "/admin/users/{$targetUser->id}/reset-password"],
        ['post', "/admin/users/{$targetUser->id}/active"],
        ['get', '/admin/roles'],
        ['get', '/admin/roles/create'],
        ['post', '/admin/roles'],
        ['get', "/admin/roles/{$targetRole}"],
        ['get', "/admin/roles/{$targetRole}/edit"],
        ['put', "/admin/roles/{$targetRole}"],
        ['delete', "/admin/roles/{$targetRole}"],
        ['post', "/admin/roles/{$targetRole}/duplicate"],
        ['get', '/admin/audit-logs'],
    ];

    foreach ($routes as [$method, $uri]) {
        $this->actingAs(User::findOrFail($user->id))->{$method}($uri)->assertStatus(403);
        $this->flushSession();
    }
})->with(['purchasing', 'warehouse', 'sales', 'finance', 'viewer']);

test('writes under /admin are rate limited per user', function (): void {
    $admin = userWithPermissions(['users.create']);

    foreach (range(1, 60) as $attempt) {
        $this->actingAs($admin)->post('/admin/users', [])->assertStatus(302);
    }

    $this->actingAs($admin)->post('/admin/users', [])->assertStatus(429);
    $this->actingAs($admin)->get('/admin/users/create')->assertOk();
});

test('the admin system role can open every administration page', function (): void {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'admin')->value('id'), ['created_at' => now()]);

    foreach (['/admin/users', '/admin/users/create', '/admin/roles', '/admin/roles/create', '/admin/audit-logs'] as $uri) {
        $this->actingAs(User::findOrFail($user->id))->get($uri)->assertStatus(200);
        $this->flushSession();
    }
});
