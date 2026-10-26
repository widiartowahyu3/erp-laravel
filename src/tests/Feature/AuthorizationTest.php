<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Ensure permissions and roles are seeded
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

test('permission catalog contains exactly 98 permissions with valid formats and modules', function () {
    $permissions = Permission::all();

    expect($permissions->count())->toBe(98);

    $validModules = [
        'administration',
        'master_data',
        'purchasing',
        'inventory',
        'sales',
        'finance',
        'reporting',
    ];

    $names = [];
    foreach ($permissions as $permission) {
        // Unique names
        expect(in_array($permission->name, $names, true))->toBeFalse();
        $names[] = $permission->name;

        // Name format ^[a-z_]+\.[a-z_]+$
        expect(preg_match('/^[a-z_]+\.[a-z_]+$/', $permission->name))->toBe(1);

        // Valid module
        expect($validModules)->toContain($permission->module);
    }
});

test('super_admin role has all 98 permissions synchronized via seeder without gate bypass', function () {
    $superAdminRole = Role::where('name', 'super_admin')->firstOrFail();
    expect($superAdminRole->permissions()->count())->toBe(98);

    $user = User::factory()->create();
    $user->roles()->attach($superAdminRole->id, ['created_at' => now()]);

    // Check that super_admin has all abilities allowed via Gate
    $sampleAbilities = [
        'users.create',
        'roles.update',
        'purchase_orders.approve',
        'goods_receipts.post',
        'sales_orders.void',
        'journals.post',
        'reports.view_finance',
    ];

    foreach ($sampleAbilities as $ability) {
        expect(Gate::forUser($user)->allows($ability))->toBeTrue();
    }
});

test('role permission matrices enforce strict segregation of duties', function () {
    $rolesToTest = [
        'admin' => [
            'allowed' => ['users.view', 'roles.create', 'products.view', 'settings.view', 'audit_logs.view'],
            'forbidden' => ['purchase_orders.approve', 'purchase_orders.void', 'goods_receipts.post', 'sales_orders.approve', 'journals.post'],
        ],
        'purchasing' => [
            'allowed' => ['purchase_orders.view', 'purchase_orders.approve', 'suppliers.create', 'goods_receipts.view', 'reports.view_purchasing'],
            'forbidden' => ['sales_orders.create', 'sales_orders.approve', 'users.view', 'journals.post', 'delivery_orders.create'],
        ],
        'warehouse' => [
            'allowed' => ['goods_receipts.create', 'goods_receipts.post', 'stock_adjustments.post', 'delivery_orders.post', 'stock_movements.view'],
            'forbidden' => ['purchase_orders.approve', 'purchase_orders.create', 'sales_orders.approve', 'users.view', 'journals.post'],
        ],
        'sales' => [
            'allowed' => ['sales_orders.view', 'sales_orders.create', 'sales_orders.approve', 'customers.create', 'reports.view_sales'],
            'forbidden' => ['purchase_orders.approve', 'goods_receipts.post', 'journals.post', 'users.view', 'roles.view'],
        ],
        'finance' => [
            'allowed' => ['chart_of_accounts.create', 'journals.post', 'sales_invoices.post', 'purchase_invoices.post', 'payments.post'],
            'forbidden' => ['users.create', 'roles.create', 'settings.update', 'products.create', 'warehouses.create'],
        ],
        'viewer' => [
            'allowed' => ['products.view', 'purchase_orders.view', 'sales_orders.view', 'stock_balances.view', 'reports.view_sales'],
            'forbidden' => ['users.view', 'roles.view', 'settings.view', 'audit_logs.view', 'products.create'],
        ],
    ];

    foreach ($rolesToTest as $roleName => $matrix) {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create();
        $user->roles()->attach($role->id, ['created_at' => now()]);

        // At least 5 allowed
        expect(count($matrix['allowed']))->toBeGreaterThanOrEqual(5);
        foreach ($matrix['allowed'] as $allowedAbility) {
            expect(Gate::forUser($user)->allows($allowedAbility))
                ->toBeTrue("Role {$roleName} was expected to allow {$allowedAbility}");
        }

        // At least 5 forbidden
        expect(count($matrix['forbidden']))->toBeGreaterThanOrEqual(5);
        foreach ($matrix['forbidden'] as $forbiddenAbility) {
            expect(Gate::forUser($user)->allows($forbiddenAbility))
                ->toBeFalse("Role {$roleName} was expected to forbid {$forbiddenAbility}");
        }
    }
});

test('user without roles is denied all permissions', function () {
    $user = User::factory()->create();

    expect($user->roles()->count())->toBe(0);
    expect($user->getPermissionNames())->toBeEmpty();

    $abilities = [
        'users.view',
        'products.view',
        'purchase_orders.view',
        'sales_orders.view',
        'journals.view',
        'reports.view_sales',
    ];

    foreach ($abilities as $ability) {
        expect(Gate::forUser($user)->allows($ability))->toBeFalse();
    }
});

test('unknown abilities are strictly denied even for super_admin', function () {
    $superAdminRole = Role::where('name', 'super_admin')->firstOrFail();
    $user = User::factory()->create();
    $user->roles()->attach($superAdminRole->id, ['created_at' => now()]);

    expect(Gate::forUser($user)->allows('unknown.nonexistent_ability'))->toBeFalse();
    expect(Gate::forUser($user)->allows('arbitrary_action'))->toBeFalse();
});

test('routes with can middleware return 403 when user lacks permission', function () {
    Route::get('/test-protected-can-route', function () {
        return 'allowed';
    })->middleware(['web', 'auth', 'can:users.view']);

    $userWithoutPermission = User::factory()->create();

    $response = $this->actingAs($userWithoutPermission)->get('/test-protected-can-route');
    $response->assertStatus(403);

    // Now give user the role with users.view
    $adminRole = Role::where('name', 'admin')->firstOrFail();
    $userWithPermission = User::factory()->create();
    $userWithPermission->roles()->attach($adminRole->id, ['created_at' => now()]);

    $responseAllowed = $this->actingAs($userWithPermission)->get('/test-protected-can-route');
    $responseAllowed->assertOk();
    expect($responseAllowed->getContent())->toBe('allowed');
});

test('seeders are idempotent and never create users', function () {
    $initialUserCount = User::withTrashed()->count();

    // Re-run seeders
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(DatabaseSeeder::class);

    // Permission count remains 98
    expect(Permission::count())->toBe(98);

    // Role count remains 7
    expect(Role::count())->toBe(7);

    // Pivot count remains exactly 275
    expect(DB::table('permission_role')->count())->toBe(275);

    // User count remains strictly unchanged
    expect(User::withTrashed()->count())->toBe($initialUserCount);
});
