<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\Warehouse;

test('units crud lifecycle and permission checks', function (): void {
    $viewer = userWithPermissions(['units.view']);
    $creator = userWithPermissions(['units.create']);
    $editor = userWithPermissions(['units.update']);
    $destroyer = userWithPermissions(['units.delete']);
    $fullAdmin = userWithPermissions(['units.view', 'units.create', 'units.update', 'units.delete']);

    // 1. Index
    $this->actingAs($creator)->get('/master/units')->assertStatus(403);
    $this->flushSession();
    $this->actingAs($viewer)->get('/master/units')->assertStatus(200);

    // 2. Create Page & Store
    $this->flushSession();
    $this->actingAs($viewer)->get('/master/units/create')->assertStatus(403);
    $this->flushSession();
    $this->actingAs($creator)->get('/master/units/create')->assertStatus(200);

    $storeData = ['code' => 'TUNIT', 'name' => 'Test Unit Satuan'];
    $this->flushSession();
    $this->actingAs($viewer)->post('/master/units', $storeData)->assertStatus(403);

    $this->flushSession();
    $storeResponse = $this->actingAs($creator)->post('/master/units', $storeData);
    $storeResponse->assertRedirect('/master/units');
    $storeResponse->assertSessionHas('success');

    /** @var Unit $unit */
    $unit = Unit::where('code', 'TUNIT')->firstOrFail();
    expect($unit->name)->toBe('Test Unit Satuan');

    // 3. Edit & Update
    $this->flushSession();
    $this->actingAs($creator)->get("/master/units/{$unit->id}/edit")->assertStatus(403);
    $this->flushSession();
    $this->actingAs($editor)->get("/master/units/{$unit->id}/edit")->assertStatus(200);

    $updateData = ['code' => 'TUNIT', 'name' => 'Updated Satuan Name'];
    $this->flushSession();
    $this->actingAs($viewer)->put("/master/units/{$unit->id}", $updateData)->assertStatus(403);

    $this->flushSession();
    $updateResponse = $this->actingAs($editor)->put("/master/units/{$unit->id}", $updateData);
    $updateResponse->assertRedirect('/master/units');

    $unit->refresh();
    expect($unit->name)->toBe('Updated Satuan Name');

    // 4. Destroy
    $this->flushSession();
    $this->actingAs($editor)->delete("/master/units/{$unit->id}")->assertStatus(403);

    $this->flushSession();
    $deleteResponse = $this->actingAs($destroyer)->delete("/master/units/{$unit->id}");
    $deleteResponse->assertRedirect();
    $deleteResponse->assertSessionHas('success');

    expect(Unit::find($unit->id))->toBeNull();
    expect(Unit::withTrashed()->find($unit->id))->not->toBeNull();
});

test('product categories crud lifecycle and permission checks', function (): void {
    $full = userWithPermissions(['product_categories.view', 'product_categories.create', 'product_categories.update', 'product_categories.delete']);

    // Index & Create
    $this->actingAs($full)->get('/master/product-categories')->assertStatus(200);
    $this->actingAs($full)->get('/master/product-categories/create')->assertStatus(200);

    // Store Parent
    $this->actingAs($full)->post('/master/product-categories', [
        'code' => 'PARCAT',
        'name' => 'Parent Category Test',
    ])->assertRedirect('/master/product-categories');

    /** @var ProductCategory $parent */
    $parent = ProductCategory::where('code', 'PARCAT')->firstOrFail();

    // Store Child
    $this->actingAs($full)->post('/master/product-categories', [
        'code' => 'CHLCAT',
        'name' => 'Child Category Test',
        'parent_id' => $parent->id,
    ])->assertRedirect('/master/product-categories');

    /** @var ProductCategory $child */
    $child = ProductCategory::where('code', 'CHLCAT')->firstOrFail();
    expect($child->parent_id)->toBe($parent->id);

    // Edit & Update
    $this->actingAs($full)->get("/master/product-categories/{$child->id}/edit")->assertStatus(200);
    $this->actingAs($full)->put("/master/product-categories/{$child->id}", [
        'code' => 'CHLCAT',
        'name' => 'Child Category Renamed',
        'parent_id' => $parent->id,
    ])->assertRedirect('/master/product-categories');

    $child->refresh();
    expect($child->name)->toBe('Child Category Renamed');

    // Destroy
    $this->actingAs($full)->delete("/master/product-categories/{$child->id}")->assertRedirect();
    expect(ProductCategory::find($child->id))->toBeNull();
});

test('warehouses crud lifecycle and permission checks', function (): void {
    $full = userWithPermissions(['warehouses.view', 'warehouses.create', 'warehouses.update', 'warehouses.delete']);

    // Index & Create
    $this->actingAs($full)->get('/master/warehouses')->assertStatus(200);
    $this->actingAs($full)->get('/master/warehouses/create')->assertStatus(200);

    // Store
    $this->actingAs($full)->post('/master/warehouses', [
        'code' => 'WH-TEST',
        'name' => 'Gudang Pengujian',
        'address' => 'Jl. Uji Gudang No. 1',
        'is_active' => true,
    ])->assertRedirect('/master/warehouses');

    /** @var Warehouse $warehouse */
    $warehouse = Warehouse::where('code', 'WH-TEST')->firstOrFail();

    // Edit & Update
    $this->actingAs($full)->get("/master/warehouses/{$warehouse->id}/edit")->assertStatus(200);
    $this->actingAs($full)->put("/master/warehouses/{$warehouse->id}", [
        'code' => 'WH-TEST',
        'name' => 'Gudang Pengujian Diubah',
        'address' => 'Jl. Uji Gudang No. 2',
        'is_active' => false,
    ])->assertRedirect('/master/warehouses');

    $warehouse->refresh();
    expect($warehouse->name)->toBe('Gudang Pengujian Diubah')
        ->and($warehouse->is_active)->toBeFalse();

    // Destroy
    $this->actingAs($full)->delete("/master/warehouses/{$warehouse->id}")->assertRedirect();
    expect(Warehouse::find($warehouse->id))->toBeNull();
});

test('tax rates crud lifecycle and permission checks', function (): void {
    $full = userWithPermissions(['tax_rates.view', 'tax_rates.create', 'tax_rates.update', 'tax_rates.delete']);

    // Index & Create
    $this->actingAs($full)->get('/master/tax-rates')->assertStatus(200);
    $this->actingAs($full)->get('/master/tax-rates/create')->assertStatus(200);

    // Store
    $this->actingAs($full)->post('/master/tax-rates', [
        'code' => 'TX-TEST',
        'name' => 'Tarif Pajak Uji',
        'rate' => '7.50',
        'is_active' => true,
    ])->assertRedirect('/master/tax-rates');

    /** @var TaxRate $taxRate */
    $taxRate = TaxRate::where('code', 'TX-TEST')->firstOrFail();
    expect($taxRate->rate)->toBe('7.50');

    // Edit & Update
    $this->actingAs($full)->get("/master/tax-rates/{$taxRate->id}/edit")->assertStatus(200);
    $this->actingAs($full)->put("/master/tax-rates/{$taxRate->id}", [
        'code' => 'TX-TEST',
        'name' => 'Tarif Pajak Uji Diubah',
        'rate' => '8.00',
        'is_active' => true,
    ])->assertRedirect('/master/tax-rates');

    $taxRate->refresh();
    expect($taxRate->rate)->toBe('8.00');

    // Destroy
    $this->actingAs($full)->delete("/master/tax-rates/{$taxRate->id}")->assertRedirect();
    expect(TaxRate::find($taxRate->id))->toBeNull();
});

test('payment terms crud lifecycle and permission checks', function (): void {
    $full = userWithPermissions(['payment_terms.view', 'payment_terms.create', 'payment_terms.update', 'payment_terms.delete']);

    // Index & Create
    $this->actingAs($full)->get('/master/payment-terms')->assertStatus(200);
    $this->actingAs($full)->get('/master/payment-terms/create')->assertStatus(200);

    // Store
    $this->actingAs($full)->post('/master/payment-terms', [
        'code' => 'TERM-45',
        'name' => 'Tempo 45 Hari',
        'days' => 45,
    ])->assertRedirect('/master/payment-terms');

    /** @var PaymentTerm $term */
    $term = PaymentTerm::where('code', 'TERM-45')->firstOrFail();
    expect($term->days)->toBe(45);

    // Edit & Update
    $this->actingAs($full)->get("/master/payment-terms/{$term->id}/edit")->assertStatus(200);
    $this->actingAs($full)->put("/master/payment-terms/{$term->id}", [
        'code' => 'TERM-45',
        'name' => 'Tempo 45 Hari Revisi',
        'days' => 45,
    ])->assertRedirect('/master/payment-terms');

    $term->refresh();
    expect($term->name)->toBe('Tempo 45 Hari Revisi');

    // Destroy
    $this->actingAs($full)->delete("/master/payment-terms/{$term->id}")->assertRedirect();
    expect(PaymentTerm::find($term->id))->toBeNull();
});

test('suppliers crud lifecycle and permission checks', function (): void {
    $full = userWithPermissions(['suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete']);

    // Index & Create
    $this->actingAs($full)->get('/master/suppliers')->assertStatus(200);
    $this->actingAs($full)->get('/master/suppliers/create')->assertStatus(200);

    // Store
    $this->actingAs($full)->post('/master/suppliers', [
        'code' => 'SUP-TEST1',
        'name' => 'PT Mitra Pemasok Pengujian',
        'contact_name' => 'Budi Santoso',
        'phone' => '08123456789',
        'email' => 'budi@pemasokuji.com',
        'address' => 'Jl. Pemasok Raya No. 10',
        'tax_id' => '01.234.567.8-999.000',
        'is_active' => true,
    ])->assertRedirect('/master/suppliers');

    /** @var Supplier $supplier */
    $supplier = Supplier::where('code', 'SUP-TEST1')->firstOrFail();

    // Edit & Update
    $this->actingAs($full)->get("/master/suppliers/{$supplier->id}/edit")->assertStatus(200);
    $this->actingAs($full)->put("/master/suppliers/{$supplier->id}", [
        'code' => 'SUP-TEST1',
        'name' => 'PT Mitra Pemasok Pengujian Sukses',
        'contact_name' => 'Budi Santoso S.E.',
        'phone' => '08123456789',
        'email' => 'budi@pemasokuji.com',
        'is_active' => true,
    ])->assertRedirect('/master/suppliers');

    $supplier->refresh();
    expect($supplier->name)->toBe('PT Mitra Pemasok Pengujian Sukses');

    // Destroy
    $this->actingAs($full)->delete("/master/suppliers/{$supplier->id}")->assertRedirect();
    expect(Supplier::find($supplier->id))->toBeNull();
});

test('customers crud lifecycle and permission checks', function (): void {
    $full = userWithPermissions(['customers.view', 'customers.create', 'customers.update', 'customers.delete']);

    // Index & Create
    $this->actingAs($full)->get('/master/customers')->assertStatus(200);
    $this->actingAs($full)->get('/master/customers/create')->assertStatus(200);

    // Store
    $this->actingAs($full)->post('/master/customers', [
        'code' => 'CUST-TEST1',
        'name' => 'Toko Pelanggan Uji',
        'contact_name' => 'Siti Nurhaliza',
        'phone' => '08198765432',
        'email' => 'siti@pelangganuji.id',
        'address' => 'Jl. Pelanggan Blok A',
        'credit_limit' => '15000000.00',
        'is_active' => true,
    ])->assertRedirect('/master/customers');

    /** @var Customer $customer */
    $customer = Customer::where('code', 'CUST-TEST1')->firstOrFail();
    expect($customer->credit_limit)->toBe('15000000.00');

    // Edit & Update
    $this->actingAs($full)->get("/master/customers/{$customer->id}/edit")->assertStatus(200);
    $this->actingAs($full)->put("/master/customers/{$customer->id}", [
        'code' => 'CUST-TEST1',
        'name' => 'Toko Pelanggan Uji Makmur',
        'credit_limit' => '20000000.00',
        'is_active' => true,
    ])->assertRedirect('/master/customers');

    $customer->refresh();
    expect($customer->credit_limit)->toBe('20000000.00');

    // Destroy
    $this->actingAs($full)->delete("/master/customers/{$customer->id}")->assertRedirect();
    expect(Customer::find($customer->id))->toBeNull();
});

test('products crud lifecycle and permission checks', function (): void {
    $full = userWithPermissions(['products.view', 'products.create', 'products.update', 'products.delete']);

    // Create dependencies
    $unit = Unit::create(['code' => 'UPRD', 'name' => 'Unit Produk']);
    $cat = ProductCategory::create(['code' => 'CPRD', 'name' => 'Kategori Produk']);

    // Index & Create
    $this->actingAs($full)->get('/master/products')->assertStatus(200);
    $this->actingAs($full)->get('/master/products/create')->assertStatus(200);

    // Store
    $this->actingAs($full)->post('/master/products', [
        'sku' => 'SKU-TEST-001',
        'name' => 'Produk Dagang Eksklusif',
        'category_id' => $cat->id,
        'base_unit_id' => $unit->id,
        'barcode' => '899999990001',
        'selling_price' => '125000.00',
        'min_stock' => '5.0000',
        'track_stock' => true,
        'is_active' => true,
    ])->assertRedirect('/master/products');

    /** @var Product $product */
    $product = Product::where('sku', 'SKU-TEST-001')->firstOrFail();
    expect($product->selling_price)->toBe('125000.00')
        ->and($product->min_stock)->toBe('5.0000');

    // Edit & Update
    $this->actingAs($full)->get("/master/products/{$product->id}/edit")->assertStatus(200);
    $this->actingAs($full)->put("/master/products/{$product->id}", [
        'sku' => 'SKU-TEST-001',
        'name' => 'Produk Dagang Eksklusif Revisi',
        'category_id' => $cat->id,
        'base_unit_id' => $unit->id,
        'selling_price' => '135000.00',
        'min_stock' => '10.0000',
        'track_stock' => true,
        'is_active' => true,
    ])->assertRedirect('/master/products');

    $product->refresh();
    expect($product->selling_price)->toBe('135000.00')
        ->and($product->min_stock)->toBe('10.0000');

    // Destroy
    $this->actingAs($full)->delete("/master/products/{$product->id}")->assertRedirect();
    expect(Product::find($product->id))->toBeNull();
});
