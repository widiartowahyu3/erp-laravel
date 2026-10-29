<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Services\MasterData\ProductCategoryService;
use App\Services\MasterData\ReferenceGuard;

test('unique validation ignores self on update and rejects duplicate active records', function (): void {
    $admin = userWithPermissions(['units.create', 'units.update']);

    // 1. Create two units
    $unitA = Unit::create(['code' => 'UNITA', 'name' => 'Unit Alpha']);
    $unitB = Unit::create(['code' => 'UNITB', 'name' => 'Unit Beta']);

    // 2. Updating unitA keeping same code succeeds (ignore self)
    $this->actingAs($admin)->put("/master/units/{$unitA->id}", [
        'code' => 'UNITA',
        'name' => 'Unit Alpha Renamed',
    ])->assertRedirect('/master/units');

    // 3. Updating unitB to use unitA code fails with validation error (422)
    $response = $this->actingAs($admin)->put("/master/units/{$unitB->id}", [
        'code' => 'UNITA',
        'name' => 'Unit Beta Duplicate',
    ]);
    $response->assertSessionHasErrors(['code']);
});

test('input fields are normalized automatically on store and update', function (): void {
    $admin = userWithPermissions(['units.create', 'suppliers.create', 'customers.create']);

    // 1. Unit code should be uppercase and trimmed
    $this->actingAs($admin)->post('/master/units', [
        'code' => '  pcs_x  ',
        'name' => '  Pieces Extra  ',
    ])->assertRedirect('/master/units');

    /** @var Unit $unit */
    $unit = Unit::where('code', 'PCS_X')->firstOrFail();
    expect($unit->name)->toBe('Pieces Extra');

    // 2. Supplier email is lowercased and spaces trimmed
    $this->actingAs($admin)->post('/master/suppliers', [
        'code' => '  sup-norm  ',
        'name' => '  CV Mitra Bersama  ',
        'email' => '  SALES@MITRA.COM  ',
    ])->assertRedirect('/master/suppliers');

    /** @var Supplier $supplier */
    $supplier = Supplier::where('code', 'SUP-NORM')->firstOrFail();
    expect($supplier->email)->toBe('sales@mitra.com')
        ->and($supplier->name)->toBe('CV Mitra Bersama');
});

test('decimal validation rejects invalid, non-decimal, and negative numbers', function (): void {
    $admin = userWithPermissions(['products.create', 'tax_rates.create', 'customers.create']);
    $unit = Unit::create(['code' => 'UDEC', 'name' => 'Unit Dec']);
    $cat = ProductCategory::create(['code' => 'CDEC', 'name' => 'Cat Dec']);

    // Product selling_price negative or non-decimal
    $this->actingAs($admin)->post('/master/products', [
        'sku' => 'SKU-DEC-1',
        'name' => 'Produk Dec 1',
        'category_id' => $cat->id,
        'base_unit_id' => $unit->id,
        'selling_price' => '-500.00',
        'min_stock' => '1.0000',
    ])->assertSessionHasErrors(['selling_price']);

    $this->actingAs($admin)->post('/master/products', [
        'sku' => 'SKU-DEC-2',
        'name' => 'Produk Dec 2',
        'category_id' => $cat->id,
        'base_unit_id' => $unit->id,
        'selling_price' => 'not-a-number',
        'min_stock' => '1.0000',
    ])->assertSessionHasErrors(['selling_price']);

    // Product min_stock negative
    $this->actingAs($admin)->post('/master/products', [
        'sku' => 'SKU-DEC-3',
        'name' => 'Produk Dec 3',
        'category_id' => $cat->id,
        'base_unit_id' => $unit->id,
        'selling_price' => '1000.00',
        'min_stock' => '-2.5000',
    ])->assertSessionHasErrors(['min_stock']);

    // TaxRate rate > 100 or < 0
    $this->actingAs($admin)->post('/master/tax-rates', [
        'code' => 'TX-ERR1',
        'name' => 'Pajak Error 1',
        'rate' => '105.00',
    ])->assertSessionHasErrors(['rate']);

    $this->actingAs($admin)->post('/master/tax-rates', [
        'code' => 'TX-ERR2',
        'name' => 'Pajak Error 2',
        'rate' => '-5.00',
    ])->assertSessionHasErrors(['rate']);
});

test('product category hierarchy rejects self-parent, cycles, and depth exceeding 5', function (): void {
    $service = app(ProductCategoryService::class);

    // 1. Self parent
    $cat1 = ProductCategory::create(['code' => 'LVL1', 'name' => 'Level 1']);

    expect(fn () => $service->update($cat1, ['code' => 'LVL1', 'name' => 'Level 1', 'parent_id' => $cat1->id]))
        ->toThrow(BusinessRuleException::class, 'Kategori tidak boleh menjadi induk untuk dirinya sendiri.');

    // 2. Cycle (Cat 1 -> Cat 2 -> Cat 3 -> Cat 1)
    $cat2 = ProductCategory::create(['code' => 'LVL2', 'name' => 'Level 2', 'parent_id' => $cat1->id]);
    $cat3 = ProductCategory::create(['code' => 'LVL3', 'name' => 'Level 3', 'parent_id' => $cat2->id]);

    expect(fn () => $service->update($cat1, ['code' => 'LVL1', 'name' => 'Level 1', 'parent_id' => $cat3->id]))
        ->toThrow(BusinessRuleException::class, 'siklus');

    // 3. Depth > 5 (Level 1 -> 2 -> 3 -> 4 -> 5 -> 6)
    $cat4 = ProductCategory::create(['code' => 'LVL4', 'name' => 'Level 4', 'parent_id' => $cat3->id]);
    $cat5 = ProductCategory::create(['code' => 'LVL5', 'name' => 'Level 5', 'parent_id' => $cat4->id]);

    // Creating level 6 with parent Level 5 must throw depth > 5 exception
    expect(fn () => $service->create(['code' => 'LVL6', 'name' => 'Level 6', 'parent_id' => $cat5->id]))
        ->toThrow(BusinessRuleException::class, 'Kedalaman kategori maksimal 5 level');
});

test('reference guard prevents deleting master records referenced downstream', function (): void {
    $unit = Unit::create(['code' => 'UREF', 'name' => 'Unit Ref']);
    $cat = ProductCategory::create(['code' => 'CREF', 'name' => 'Cat Ref']);

    // Create a product referencing this unit and category
    $product = Product::create([
        'sku' => 'PRD-REF-01',
        'name' => 'Produk Rujukan',
        'category_id' => $cat->id,
        'base_unit_id' => $unit->id,
        'selling_price' => '10000.00',
        'min_stock' => '0.0000',
    ]);

    // Attempting to delete unit directly via ReferenceGuard must throw BusinessRuleException (409)
    try {
        ReferenceGuard::assertCanDelete($unit, 'Satuan Ukuran');
        $this->fail('Expected BusinessRuleException was not thrown for Unit deletion.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(409)
            ->and($e->getMessage())->toContain('Satuan Ukuran')
            ->and($e->getMessage())->toContain('Produk');
    }

    // Attempting to delete category directly via ReferenceGuard must throw 409
    try {
        ReferenceGuard::assertCanDelete($cat, 'Kategori Produk');
        $this->fail('Expected BusinessRuleException was not thrown for ProductCategory deletion.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(409)
            ->and($e->getMessage())->toContain('Kategori Produk')
            ->and($e->getMessage())->toContain('Produk');
    }

    // Controller destroy action returns 409 and flash error
    $destroyer = userWithPermissions(['units.delete']);
    $response = $this->actingAs($destroyer)->delete("/master/units/{$unit->id}");
    $response->assertStatus(409);
    $response->assertSessionHas('error');

    // JSON delete returns 409 JSON
    $jsonResponse = $this->actingAs($destroyer)->deleteJson("/master/units/{$unit->id}");
    $jsonResponse->assertStatus(409)
        ->assertJsonStructure(['message']);
});
