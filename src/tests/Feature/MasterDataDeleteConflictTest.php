<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Unit;
use Tests\Support\TestDataFactory;

test('deleting referenced master record with inertia request returns 303 redirect with error flash', function (): void {
    $unitId = TestDataFactory::createUnit(['name' => 'Referenced Unit']);
    $unit = Unit::findOrFail($unitId);

    // Reference the unit from a product
    TestDataFactory::createProduct(['base_unit_id' => $unitId]);

    $destroyer = userWithPermissions(['units.delete']);

    // Send DELETE with X-Inertia: true
    $response = $this->actingAs($destroyer)->delete("/master/units/{$unit->id}", [], [
        'X-Inertia' => 'true',
    ]);

    // Inertia requires redirect status (303 for DELETE), NOT 409
    expect($response->status())->toBe(303);
    $response->assertSessionHas('error');
    $response->assertSessionHas('error', fn ($msg) => str_contains((string) $msg, 'Tidak dapat menghapus data:'));
});

test('deleting referenced master record with json request returns 409 response', function (): void {
    $unitId = TestDataFactory::createUnit(['name' => 'Referenced Unit JSON']);
    $unit = Unit::findOrFail($unitId);

    TestDataFactory::createProduct(['base_unit_id' => $unitId]);

    $destroyer = userWithPermissions(['units.delete']);

    $jsonResponse = $this->actingAs($destroyer)->deleteJson("/master/units/{$unit->id}");

    expect($jsonResponse->status())->toBe(409);
    $jsonResponse->assertJsonStructure(['message', 'errors' => ['form']]);
});
