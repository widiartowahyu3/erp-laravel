<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

test('rate limiter 429 redirect does not flash password fields into session', function (): void {
    $user = User::factory()->create();
    RateLimiter::clear((string) $user->getAuthIdentifier());

    $this->actingAs($user);

    // Consume 60 requests through authenticated write route
    for ($i = 1; $i <= 60; $i++) {
        $this->put('/account/password', [], ['X-Inertia' => 'true']);
    }

    $secretCurrent = 'CurrentSecret#123';
    $secretNew = 'NewSecretPassword#456';

    // 61st request triggers ThrottleRequestsException (429) via Inertia (303 redirect)
    $response = $this->from('/account/password')->put('/account/password', [
        'current_password' => $secretCurrent,
        'password' => $secretNew,
        'password_confirmation' => $secretNew,
        'non_secret_field' => 'preserve_me',
    ], [
        'X-Inertia' => 'true',
    ]);

    expect($response->status())->toBe(303);

    $oldInput = session('_old_input', []);

    // Password fields must NOT be in session
    expect($oldInput)->not->toHaveKey('password')
        ->and($oldInput)->not->toHaveKey('current_password')
        ->and($oldInput)->not->toHaveKey('password_confirmation')
        ->and(json_encode(session()->all()))->not->toContain($secretCurrent)
        ->and(json_encode(session()->all()))->not->toContain($secretNew);

    // Positive control: non-secret field is preserved
    expect($oldInput)->toHaveKey('non_secret_field', 'preserve_me');
});

test('unknown unique constraint 303 redirect does not flash password fields into session', function (): void {
    $user = User::factory()->create();

    Route::post('/_test/unknown-unique-password', function () {
        $supId = TestDataFactory::createSupplier();
        $whId = TestDataFactory::createWarehouse();

        DB::table('purchase_orders')->insert([
            'number' => 'PO-DUP-TEST',
            'supplier_id' => $supId,
            'warehouse_id' => $whId,
            'order_date' => '2026-11-01',
            'status' => 'draft',
            'subtotal' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_orders')->insert([
            'number' => 'PO-DUP-TEST',
            'supplier_id' => $supId,
            'warehouse_id' => $whId,
            'order_date' => '2026-11-01',
            'status' => 'draft',
            'subtotal' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->middleware(['web', 'auth']);

    $this->actingAs($user);

    $secretPassword = 'MySecretPlainPassword!999';

    $response = $this->from('/account/password')->post('/_test/unknown-unique-password', [
        'password' => $secretPassword,
        'current_password' => $secretPassword,
        'password_confirmation' => $secretPassword,
        'non_secret_field' => 'safe_data',
    ]);

    expect($response->status())->toBe(303);

    $oldInput = session('_old_input', []);

    expect($oldInput)->not->toHaveKey('password')
        ->and($oldInput)->not->toHaveKey('current_password')
        ->and($oldInput)->not->toHaveKey('password_confirmation')
        ->and(json_encode(session()->all()))->not->toContain($secretPassword);

    expect($oldInput)->toHaveKey('non_secret_field', 'safe_data');
});
