<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Warehouse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    // Authenticate with superadmin permissions
    $user = userWithPermissions([
        'customers.view',
        'suppliers.view',
        'warehouses.view',
        'tax_rates.view',
        'products.view',
    ]);
    $this->actingAs($user);
});

test('master customers list filters by is_active=1 and is_active=0 correctly', function (): void {
    $codeActive = 'C_ACT_'.bin2hex(random_bytes(3));
    $codeInactive = 'C_INA_'.bin2hex(random_bytes(3));

    Customer::create([
        'code' => $codeActive,
        'name' => 'Active Customer',
        'is_active' => true,
    ]);

    Customer::create([
        'code' => $codeInactive,
        'name' => 'Inactive Customer',
        'is_active' => false,
    ]);

    // Active filter
    $responseActive = $this->get('/master/customers?filter[is_active]=1');
    $responseActive->assertOk();
    $responseActive->assertInertia(fn (Assert $page) => $page
        ->component('MasterData/Customers/Index')
        ->where('items.data', fn ($items) => collect($items)->contains('code', $codeActive)
            && ! collect($items)->contains('code', $codeInactive)
        )
    );

    // Inactive filter
    $responseInactive = $this->get('/master/customers?filter[is_active]=0');
    $responseInactive->assertOk();
    $responseInactive->assertInertia(fn (Assert $page) => $page
        ->component('MasterData/Customers/Index')
        ->where('items.data', fn ($items) => collect($items)->contains('code', $codeInactive)
            && ! collect($items)->contains('code', $codeActive)
        )
    );
});

test('master products list filters by category_id and is_active correctly', function (): void {
    $catA = ProductCategory::create(['code' => 'CAT_A_'.bin2hex(random_bytes(3)), 'name' => 'Cat A']);
    $catB = ProductCategory::create(['code' => 'CAT_B_'.bin2hex(random_bytes(3)), 'name' => 'Cat B']);
    $unitId = TestDataFactory::createUnit();

    $skuActiveA = 'SKU_AA_'.bin2hex(random_bytes(3));
    $skuInactiveA = 'SKU_IA_'.bin2hex(random_bytes(3));
    $skuActiveB = 'SKU_AB_'.bin2hex(random_bytes(3));

    Product::create([
        'sku' => $skuActiveA,
        'name' => 'Product Active A',
        'category_id' => $catA->id,
        'base_unit_id' => $unitId,
        'track_stock' => true,
        'selling_price' => '10000.00',
        'min_stock' => '0.0000',
        'is_active' => true,
    ]);

    Product::create([
        'sku' => $skuInactiveA,
        'name' => 'Product Inactive A',
        'category_id' => $catA->id,
        'base_unit_id' => $unitId,
        'track_stock' => true,
        'selling_price' => '10000.00',
        'min_stock' => '0.0000',
        'is_active' => false,
    ]);

    Product::create([
        'sku' => $skuActiveB,
        'name' => 'Product Active B',
        'category_id' => $catB->id,
        'base_unit_id' => $unitId,
        'track_stock' => true,
        'selling_price' => '10000.00',
        'min_stock' => '0.0000',
        'is_active' => true,
    ]);

    // Filter by category A and is_active=1
    $response = $this->get("/master/products?filter[category_id]={$catA->id}&filter[is_active]=1");
    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('MasterData/Products/Index')
        ->where('items.data', fn ($items) => collect($items)->contains('sku', $skuActiveA)
            && ! collect($items)->contains('sku', $skuInactiveA)
            && ! collect($items)->contains('sku', $skuActiveB)
        )
    );
});

test('master warehouses, suppliers and tax rates filter by is_active correctly', function (): void {
    $whActive = Warehouse::create(['code' => 'WH_A_'.bin2hex(random_bytes(3)), 'name' => 'WH Active', 'is_active' => true]);
    $whInactive = Warehouse::create(['code' => 'WH_I_'.bin2hex(random_bytes(3)), 'name' => 'WH Inactive', 'is_active' => false]);

    $resWh = $this->get('/master/warehouses?filter[is_active]=0');
    $resWh->assertOk();
    $resWh->assertInertia(fn (Assert $page) => $page
        ->where('items.data', fn ($items) => collect($items)->contains('code', $whInactive->code)
            && ! collect($items)->contains('code', $whActive->code)
        )
    );

    $supActive = Supplier::create(['code' => 'S_A_'.bin2hex(random_bytes(3)), 'name' => 'Sup Active', 'is_active' => true]);
    $supInactive = Supplier::create(['code' => 'S_I_'.bin2hex(random_bytes(3)), 'name' => 'Sup Inactive', 'is_active' => false]);

    $resSup = $this->get('/master/suppliers?filter[is_active]=1');
    $resSup->assertOk();
    $resSup->assertInertia(fn (Assert $page) => $page
        ->where('items.data', fn ($items) => collect($items)->contains('code', $supActive->code)
            && ! collect($items)->contains('code', $supInactive->code)
        )
    );

    $taxActive = TaxRate::create(['code' => 'T_A_'.bin2hex(random_bytes(3)), 'name' => 'Tax Active', 'rate' => '11.00', 'is_active' => true]);
    $taxInactive = TaxRate::create(['code' => 'T_I_'.bin2hex(random_bytes(3)), 'name' => 'Tax Inactive', 'rate' => '12.00', 'is_active' => false]);

    $resTax = $this->get('/master/tax-rates?filter[is_active]=0');
    $resTax->assertOk();
    $resTax->assertInertia(fn (Assert $page) => $page
        ->where('items.data', fn ($items) => collect($items)->contains('code', $taxInactive->code)
            && ! collect($items)->contains('code', $taxActive->code)
        )
    );
});
