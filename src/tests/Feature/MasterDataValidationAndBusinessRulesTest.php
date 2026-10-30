<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\MasterData\ProductCategoryService;
use App\Services\MasterData\ReferenceGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Route;

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

test('moving category calculates parent depth plus subtree height and rejects moves exceeding depth 5', function (): void {
    $service = app(ProductCategoryService::class);
    $admin = userWithPermissions(['product_categories.update']);

    // Setup Parent Branch: P1 -> P2 -> P3 (depth of P3 is 3)
    $p1 = ProductCategory::create(['code' => 'P1_BR', 'name' => 'Parent Branch 1']);
    $p2 = ProductCategory::create(['code' => 'P2_BR', 'name' => 'Parent Branch 2', 'parent_id' => $p1->id]);
    $p3 = ProductCategory::create(['code' => 'P3_BR', 'name' => 'Parent Branch 3', 'parent_id' => $p2->id]);

    // Setup Child Branch to move: C1 -> C2 -> C3 (subtree height of C1 is 3)
    $c1 = ProductCategory::create(['code' => 'C1_MV', 'name' => 'Category Move 1']);
    $c2 = ProductCategory::create(['code' => 'C2_MV', 'name' => 'Category Move 2', 'parent_id' => $c1->id]);
    $c3 = ProductCategory::create(['code' => 'C3_MV', 'name' => 'Category Move 3', 'parent_id' => $c2->id]);

    expect($service->getParentChainDepth((int) $p3->id))->toBe(3)
        ->and($service->getSubtreeHeight((int) $c1->id))->toBe(3);

    // 1. Moving C1 under P3 would result in total depth 3 + 3 = 6 > 5 -> REJECTED (422)
    expect(fn () => $service->update($c1, [
        'code' => $c1->code,
        'name' => $c1->name,
        'parent_id' => $p3->id,
    ]))->toThrow(BusinessRuleException::class, 'Kedalaman kategori maksimal 5 level');

    // HTTP PUT request receives 422 / error session
    $this->actingAs($admin)->putJson("/master/product-categories/{$c1->id}", [
        'code' => $c1->code,
        'name' => $c1->name,
        'parent_id' => $p3->id,
    ])->assertStatus(422);

    // 2. Moving C1 under P2 results in total depth 2 + 3 = 5 <= 5 -> SUCCEEDS
    $validResponse = $this->actingAs($admin)->put("/master/product-categories/{$c1->id}", [
        'code' => $c1->code,
        'name' => $c1->name,
        'parent_id' => $p2->id,
    ]);
    $validResponse->assertRedirect('/master/product-categories');
    expect($c1->fresh()->parent_id)->toBe($p2->id);

    // 3. Cycle remains rejected: moving P1 under C3 (which is now under P1 -> P2 -> C1 -> C2 -> C3)
    expect(fn () => $service->update($p1, [
        'code' => $p1->code,
        'name' => $p1->name,
        'parent_id' => $c3->id,
    ]))->toThrow(BusinessRuleException::class, 'siklus');
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

dataset('masterDataUniqueModules', function () {
    return [
        'units' => [
            'units',
            '/master/units',
            'code',
            ['units.create', 'units.update', 'units.delete'],
            fn () => Unit::create(['code' => 'U_DEL_1', 'name' => 'Unit Del 1']),
            fn (string $c) => ['code' => $c, 'name' => 'Unit '.$c],
            Unit::class,
        ],
        'product_categories' => [
            'product_categories',
            '/master/product-categories',
            'code',
            ['product_categories.create', 'product_categories.update', 'product_categories.delete'],
            fn () => ProductCategory::create(['code' => 'CAT_DEL_1', 'name' => 'Cat Del 1']),
            fn (string $c) => ['code' => $c, 'name' => 'Cat '.$c],
            ProductCategory::class,
        ],
        'products' => [
            'products',
            '/master/products',
            'sku',
            ['products.create', 'products.update', 'products.delete'],
            function () {
                $u = Unit::firstOrCreate(['code' => 'U_P_BASE'], ['name' => 'Base Unit']);
                $cat = ProductCategory::firstOrCreate(['code' => 'C_P_BASE'], ['name' => 'Base Cat']);

                return Product::create([
                    'sku' => 'SKU_DEL_1',
                    'name' => 'Prod Del 1',
                    'category_id' => $cat->id,
                    'base_unit_id' => $u->id,
                    'selling_price' => '1000.00',
                    'min_stock' => '0.0000',
                ]);
            },
            function (string $c) {
                $u = Unit::firstOrCreate(['code' => 'U_P_BASE'], ['name' => 'Base Unit']);
                $cat = ProductCategory::firstOrCreate(['code' => 'C_P_BASE'], ['name' => 'Base Cat']);

                return [
                    'sku' => $c,
                    'name' => 'Prod '.$c,
                    'category_id' => $cat->id,
                    'base_unit_id' => $u->id,
                    'selling_price' => '1500.00',
                    'min_stock' => '5.0000',
                ];
            },
            Product::class,
        ],
        'warehouses' => [
            'warehouses',
            '/master/warehouses',
            'code',
            ['warehouses.create', 'warehouses.update', 'warehouses.delete'],
            fn () => Warehouse::create(['code' => 'WH_DEL_1', 'name' => 'WH Del 1']),
            fn (string $c) => ['code' => $c, 'name' => 'WH '.$c],
            Warehouse::class,
        ],
        'suppliers' => [
            'suppliers',
            '/master/suppliers',
            'code',
            ['suppliers.create', 'suppliers.update', 'suppliers.delete'],
            fn () => Supplier::create(['code' => 'SUP_DEL_1', 'name' => 'Supplier Del 1']),
            fn (string $c) => ['code' => $c, 'name' => 'Supplier '.$c],
            Supplier::class,
        ],
        'customers' => [
            'customers',
            '/master/customers',
            'code',
            ['customers.create', 'customers.update', 'customers.delete'],
            fn () => Customer::create(['code' => 'CUST_DEL_1', 'name' => 'Cust Del 1']),
            fn (string $c) => ['code' => $c, 'name' => 'Cust '.$c],
            Customer::class,
        ],
        'tax_rates' => [
            'tax_rates',
            '/master/tax-rates',
            'code',
            ['tax_rates.create', 'tax_rates.update', 'tax_rates.delete'],
            fn () => TaxRate::create(['code' => 'TX_DEL_1', 'name' => 'Tax Del 1', 'rate' => '10.00']),
            fn (string $c) => ['code' => $c, 'name' => 'Tax '.$c, 'rate' => '11.00'],
            TaxRate::class,
        ],
        'payment_terms' => [
            'payment_terms',
            '/master/payment-terms',
            'code',
            ['payment_terms.create', 'payment_terms.update', 'payment_terms.delete'],
            fn () => PaymentTerm::create(['code' => 'PT_DEL_1', 'name' => 'Term Del 1', 'days' => 30]),
            fn (string $c) => ['code' => $c, 'name' => 'Term '.$c, 'days' => 45],
            PaymentTerm::class,
        ],
    ];
});

test('unique constraints reject duplicates across all 8 modules including soft-deleted records', function (
    string $module,
    string $endpoint,
    string $codeField,
    array $permissions,
    callable $createInitial,
    callable $makePayload,
    string $modelClass
): void {
    $admin = userWithPermissions($permissions);

    // 1. Create initial record and soft-delete it
    $record1 = $createInitial();
    $code1 = (string) $record1->{$codeField};
    $record1->delete();
    expect($modelClass::onlyTrashed()->where('id', $record1->id)->exists())->toBeTrue();

    // 2. Attempting to create a new record with the same code as soft-deleted record is rejected (422)
    $payloadConflict = $makePayload($code1);
    $responseCreate = $this->actingAs($admin)->postJson($endpoint, $payloadConflict);
    $responseCreate->assertStatus(422)
        ->assertJsonValidationErrors([$codeField]);
    expect($responseCreate->json("errors.{$codeField}.0"))
        ->toContain('sudah dipakai, termasuk oleh data yang sudah dihapus');

    // 3. Creating a second record with a brand new unique code succeeds
    $newCode = substr($code1, 0, 5).'_U2';
    $payloadValid = $makePayload($newCode);
    $responseValid = $this->actingAs($admin)->post($endpoint, $payloadValid);
    $responseValid->assertRedirect($endpoint);

    /** @var Model $record2 */
    $record2 = $modelClass::where($codeField, $newCode)->firstOrFail();

    // 4. Attempting to update record 2 to use the soft-deleted code is rejected (422)
    $responseUpdate = $this->actingAs($admin)->putJson("{$endpoint}/{$record2->id}", $payloadConflict);
    $responseUpdate->assertStatus(422)
        ->assertJsonValidationErrors([$codeField]);
    expect($responseUpdate->json("errors.{$codeField}.0"))
        ->toContain('sudah dipakai, termasuk oleh data yang sudah dihapus');

    // 5. Updating record 2 with another new unique code succeeds
    $updateCode = substr($code1, 0, 5).'_U3';
    $payloadUpdateValid = $makePayload($updateCode);
    $responseUpdateValid = $this->actingAs($admin)->put("{$endpoint}/{$record2->id}", $payloadUpdateValid);
    $responseUpdateValid->assertRedirect($endpoint);
    expect($record2->fresh()->{$codeField})->toBe($updateCode);
})->with('masterDataUniqueModules');

test('centralized exception handler converts UniqueConstraintViolationException into inline 422 validation error', function (): void {
    Route::middleware('web')->post('/test-unique-violation/{type}', function (string $type) {
        $messages = [
            'units' => "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'PCS' for key 'units_code_unique'",
            'products_sku' => "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'SKU1' for key 'products_sku_unique'",
            'products_barcode' => "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'BAR1' for key 'products_barcode_unique'",
            'users_email' => "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'test@example.com' for key 'users_email_unique'",
        ];

        $pdoException = new \PDOException($messages[$type] ?? $messages['units'], 23000);
        throw new UniqueConstraintViolationException(
            'mariadb',
            'INSERT INTO test ...',
            [],
            $pdoException
        );
    });

    $user = userWithPermissions(['units.create']);

    // 1. JSON request gets 422 with field 'code'
    $this->actingAs($user)->postJson('/test-unique-violation/units')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    // 2. SKU violation maps to field 'sku'
    $this->actingAs($user)->postJson('/test-unique-violation/products_sku')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sku']);

    // 3. Barcode violation maps to field 'barcode'
    $this->actingAs($user)->postJson('/test-unique-violation/products_barcode')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['barcode']);

    // 4. Email violation maps to field 'email'
    $this->actingAs($user)->postJson('/test-unique-violation/users_email')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    // 5. Standard Web request gets redirected with session errors
    $this->actingAs($user)->post('/test-unique-violation/units')
        ->assertSessionHasErrors(['code']);
});
