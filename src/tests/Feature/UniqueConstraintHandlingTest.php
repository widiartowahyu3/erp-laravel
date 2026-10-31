<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    Route::post('/_test/unique-violation-doc', function () {
        $supId = TestDataFactory::createSupplier();
        $whId = TestDataFactory::createWarehouse();

        DB::table('purchase_orders')->insert([
            'number' => 'PO-DUP-001',
            'supplier_id' => $supId,
            'warehouse_id' => $whId,
            'order_date' => '2026-10-31',
            'status' => 'draft',
            'subtotal' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Duplicate insert to trigger purchase_orders_number_unique
        DB::table('purchase_orders')->insert([
            'number' => 'PO-DUP-001',
            'supplier_id' => $supId,
            'warehouse_id' => $whId,
            'order_date' => '2026-10-31',
            'status' => 'draft',
            'subtotal' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'ok']);
    })->middleware(['web']);
});

test('unknown unique constraint violations are reported to log and return generic conflict response', function (): void {
    Log::spy();

    // 1. JSON request to unknown unique constraint route
    $responseJson = $this->postJson('/_test/unique-violation-doc');

    // Must return 409 Conflict (not 422 validation error on field 'code')
    expect($responseJson->status())->toBe(409)
        ->and($responseJson->json('message'))->toBe('Terjadi konflik data. Silakan muat ulang dan coba lagi.')
        ->and($responseJson->json('errors'))->toBeNull();

    // 2. Inertia request to unknown unique constraint route
    $responseInertia = $this->post('/_test/unique-violation-doc', [], [
        'X-Inertia' => 'true',
    ]);

    expect($responseInertia->status())->toBe(303);
    $responseInertia->assertSessionHas('error', 'Terjadi konflik data. Silakan muat ulang dan coba lagi.');

    // 3. Must be logged via report()
    Log::shouldHaveReceived('error')->atLeast()->once();
});

test('known master data unique constraint violations return field-specific 422 error and are not reported as server errors', function (): void {
    $code = 'U'.random_int(10000, 99999);
    TestDataFactory::createUnit(['code' => $code]);

    $user = userWithPermissions(['units.create', 'units.view']);
    $this->actingAs($user);

    // Direct POST with duplicate code to master data endpoint
    $response = $this->postJson('/master/units', [
        'code' => $code,
        'name' => 'Duplicate Unit',
    ]);

    expect($response->status())->toBe(422)
        ->and($response->json('errors.code'))->not->toBeNull();
});
