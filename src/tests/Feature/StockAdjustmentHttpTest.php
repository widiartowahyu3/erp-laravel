<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StockAdjustmentStatus;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    config(['inertia.testing.ensure_pages_exist' => false]);
});

test('permission matrix enforces strict separation of duties across stock adjustment routes', function (): void {
    $wh = TestDataFactory::createWarehouse();
    $p = TestDataFactory::createProduct(['track_stock' => true]);

    $adj = StockAdjustment::create([
        'number' => 'SA/202610/00099',
        'warehouse_id' => $wh,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Matrix test',
        'status' => StockAdjustmentStatus::DRAFT,
    ]);

    $viewUser = userWithPermissions(['stock_adjustments.view']);
    $createUser = userWithPermissions(['stock_adjustments.create']);
    $updateUser = userWithPermissions(['stock_adjustments.update']);
    $postUser = userWithPermissions(['stock_adjustments.post']);
    $voidUser = userWithPermissions(['stock_adjustments.void']);

    // 1. Index & Show (stock_adjustments.view)
    $this->flushSession();
    $this->actingAs($createUser)->get('/inventory/stock-adjustments')->assertStatus(403);
    $this->flushSession();
    $this->actingAs($viewUser)->get('/inventory/stock-adjustments')->assertStatus(200);

    $this->flushSession();
    $this->actingAs($createUser)->get("/inventory/stock-adjustments/{$adj->id}")->assertStatus(403);
    $this->flushSession();
    $this->actingAs($viewUser)->get("/inventory/stock-adjustments/{$adj->id}")->assertStatus(200);

    // 2. Create & Store (stock_adjustments.create)
    $this->flushSession();
    $this->actingAs($viewUser)->get('/inventory/stock-adjustments/create')->assertStatus(403);
    $this->flushSession();
    $this->actingAs($createUser)->get('/inventory/stock-adjustments/create')->assertStatus(200);

    $storeData = [
        'warehouse_id' => $wh,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Test create',
        'items' => [['product_id' => $p, 'counted_quantity' => '5.0000', 'unit_cost' => '1000.0000']],
    ];
    $this->flushSession();
    $this->actingAs($viewUser)->post('/inventory/stock-adjustments', $storeData)->assertStatus(403);
    $this->flushSession();
    $resStore = $this->actingAs($createUser)->post('/inventory/stock-adjustments', $storeData);
    $resStore->assertRedirect();

    // 3. Edit & Update (stock_adjustments.update)
    $this->flushSession();
    $this->actingAs($createUser)->get("/inventory/stock-adjustments/{$adj->id}/edit")->assertStatus(403);
    $this->flushSession();
    $this->actingAs($updateUser)->get("/inventory/stock-adjustments/{$adj->id}/edit")->assertStatus(200);

    $updateData = [
        'warehouse_id' => $wh,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Test update',
        'items' => [['product_id' => $p, 'counted_quantity' => '6.0000', 'unit_cost' => '1000.0000']],
    ];
    $this->flushSession();
    $this->actingAs($createUser)->put("/inventory/stock-adjustments/{$adj->id}", $updateData)->assertStatus(403);
    $this->flushSession();
    $resUpdate = $this->actingAs($updateUser)->put("/inventory/stock-adjustments/{$adj->id}", $updateData);
    $resUpdate->assertRedirect("/inventory/stock-adjustments/{$adj->id}");

    // 4. Post (stock_adjustments.post)
    $this->flushSession();
    $this->actingAs($updateUser)->post("/inventory/stock-adjustments/{$adj->id}/post")->assertStatus(403);
    $this->flushSession();
    $resPost = $this->actingAs($postUser)->post("/inventory/stock-adjustments/{$adj->id}/post");
    $resPost->assertRedirect();
    expect($adj->fresh()->status)->toBe(StockAdjustmentStatus::POSTED);

    // 5. Void (stock_adjustments.void)
    $this->flushSession();
    $this->actingAs($postUser)->post("/inventory/stock-adjustments/{$adj->id}/void", ['reason' => 'Alasan void'])->assertStatus(403);
    $this->flushSession();
    $resVoid = $this->actingAs($voidUser)->post("/inventory/stock-adjustments/{$adj->id}/void", ['reason' => 'Alasan void']);
    $resVoid->assertRedirect();
    expect($adj->fresh()->status)->toBe(StockAdjustmentStatus::VOID);
});

test('full HTTP lifecycle: store -> show -> edit/update -> post -> void reverses stock correctly', function (): void {
    $admin = userWithPermissions([
        'stock_adjustments.view',
        'stock_adjustments.create',
        'stock_adjustments.update',
        'stock_adjustments.post',
        'stock_adjustments.void',
        'stock_movements.view',
    ]);
    $this->actingAs($admin);

    $wh = TestDataFactory::createWarehouse();
    $p = TestDataFactory::createProduct(['track_stock' => true]);

    $stockService = new StockService;
    // Saldo awal 10 @ 1000
    $stockService->increase($p, $wh, '10.0000', '1000.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);

    // 1. Store
    $resStore = $this->post('/inventory/stock-adjustments', [
        'warehouse_id' => $wh,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Opname Akhir Bulan',
        'items' => [
            ['product_id' => $p, 'counted_quantity' => '12.0000', 'unit_cost' => '1200.0000'],
        ],
    ]);
    $resStore->assertRedirect();
    $adj = StockAdjustment::where('reason', 'Opname Akhir Bulan')->firstOrFail();

    // 2. Show (with stock_movements.view includes movements)
    $resShow = $this->get("/inventory/stock-adjustments/{$adj->id}");
    $resShow->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page->component('Inventory/StockAdjustments/Show')->has('item')->has('movements'));

    // 3. Edit & Update
    $resEdit = $this->get("/inventory/stock-adjustments/{$adj->id}/edit");
    $resEdit->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page->component('Inventory/StockAdjustments/Edit'));

    $resUpdate = $this->put("/inventory/stock-adjustments/{$adj->id}", [
        'warehouse_id' => $wh,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Opname Akhir Bulan Revisi',
        'items' => [
            ['product_id' => $p, 'counted_quantity' => '15.0000', 'unit_cost' => '1100.0000'],
        ],
    ]);
    $resUpdate->assertRedirect("/inventory/stock-adjustments/{$adj->id}");
    expect($adj->fresh()->reason)->toBe('Opname Akhir Bulan Revisi');

    // 4. Post
    $resPost = $this->post("/inventory/stock-adjustments/{$adj->id}/post");
    $resPost->assertRedirect();
    expect($adj->fresh()->status)->toBe(StockAdjustmentStatus::POSTED);

    $balAfterPost = StockBalance::where('product_id', $p)->where('warehouse_id', $wh)->firstOrFail();
    expect($balAfterPost->quantity)->toBe('15.0000');

    // 5. Void with reason
    $resVoid = $this->post("/inventory/stock-adjustments/{$adj->id}/void", [
        'reason' => 'Pembatalan karena kesalahan hitung',
    ]);
    $resVoid->assertRedirect();
    expect($adj->fresh()->status)->toBe(StockAdjustmentStatus::VOID)
        ->and($adj->fresh()->void_reason)->toBe('Pembatalan karena kesalahan hitung');

    $balAfterVoid = StockBalance::where('product_id', $p)->where('warehouse_id', $wh)->firstOrFail();
    expect($balAfterVoid->quantity)->toBe('10.0000');
});

test('validation rejects invalid payloads on specific fields and accepts valid positive control', function (): void {
    $admin = userWithPermissions(['stock_adjustments.create', 'stock_adjustments.void']);
    $this->actingAs($admin);

    $wh = TestDataFactory::createWarehouse();
    $p = TestDataFactory::createProduct(['track_stock' => true]);

    $inactiveWh = TestDataFactory::createWarehouse(['is_active' => false]);
    $inactiveP = TestDataFactory::createProduct(['is_active' => false, 'track_stock' => true]);
    $nonTrackedP = TestDataFactory::createProduct(['track_stock' => false]);

    // Positive control: valid payload passes validation
    $validPayload = [
        'warehouse_id' => $wh,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Valid opname',
        'items' => [
            ['product_id' => $p, 'counted_quantity' => '10.0000', 'unit_cost' => '1000.0000'],
        ],
    ];
    $resValid = $this->post('/inventory/stock-adjustments', $validPayload);
    $resValid->assertSessionHasNoErrors();

    // 1. Items empty
    $this->post('/inventory/stock-adjustments', array_merge($validPayload, ['items' => []]))
        ->assertSessionHasErrors('items');

    // 2. Duplicate products in items
    $this->post('/inventory/stock-adjustments', array_merge($validPayload, [
        'items' => [
            ['product_id' => $p, 'counted_quantity' => '5.0000'],
            ['product_id' => $p, 'counted_quantity' => '6.0000'],
        ],
    ]))->assertSessionHasErrors('items.0.product_id');

    // 3. Inactive product
    $this->post('/inventory/stock-adjustments', array_merge($validPayload, [
        'items' => [['product_id' => $inactiveP, 'counted_quantity' => '5.0000']],
    ]))->assertSessionHasErrors('items.0.product_id');

    // 4. Non-stock tracked product
    $this->post('/inventory/stock-adjustments', array_merge($validPayload, [
        'items' => [['product_id' => $nonTrackedP, 'counted_quantity' => '5.0000']],
    ]))->assertSessionHasErrors('items.0.product_id');

    // 5. Inactive warehouse
    $this->post('/inventory/stock-adjustments', array_merge($validPayload, [
        'warehouse_id' => $inactiveWh,
    ]))->assertSessionHasErrors('warehouse_id');

    // 6. Counted negative
    $this->post('/inventory/stock-adjustments', array_merge($validPayload, [
        'items' => [['product_id' => $p, 'counted_quantity' => '-1.0000']],
    ]))->assertSessionHasErrors('items.0.counted_quantity');

    // 7. Counted 5 decimals
    $this->post('/inventory/stock-adjustments', array_merge($validPayload, [
        'items' => [['product_id' => $p, 'counted_quantity' => '1.12345']],
    ]))->assertSessionHasErrors('items.0.counted_quantity');

    // 8. Counted not a number
    $this->post('/inventory/stock-adjustments', array_merge($validPayload, [
        'items' => [['product_id' => $p, 'counted_quantity' => 'abc']],
    ]))->assertSessionHasErrors('items.0.counted_quantity');

    // 9. Future adjustment date
    $futureDate = now()->addDays(2)->format('Y-m-d');
    $this->post('/inventory/stock-adjustments', array_merge($validPayload, [
        'adjustment_date' => $futureDate,
    ]))->assertSessionHasErrors('adjustment_date');

    // 10. Reason > 255 chars
    $this->post('/inventory/stock-adjustments', array_merge($validPayload, [
        'reason' => str_repeat('a', 256),
    ]))->assertSessionHasErrors('reason');

    // 11. Void without reason
    $adj = StockAdjustment::create([
        'number' => 'SA/202610/00088',
        'warehouse_id' => $wh,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Void test',
        'status' => StockAdjustmentStatus::DRAFT,
    ]);
    $this->post("/inventory/stock-adjustments/{$adj->id}/void", ['reason' => ''])
        ->assertSessionHasErrors('reason');
});

test('edit non-draft redirects to show; post/void on invalid status returns error; insufficient stock on void fails on quantity', function (): void {
    $admin = userWithPermissions([
        'stock_adjustments.view',
        'stock_adjustments.create',
        'stock_adjustments.update',
        'stock_adjustments.post',
        'stock_adjustments.void',
    ]);
    $this->actingAs($admin);

    $wh = TestDataFactory::createWarehouse();
    $p = TestDataFactory::createProduct(['track_stock' => true]);

    $stockService = new StockService;
    $adjService = new StockAdjustmentService($stockService);

    // Initial stock: 10
    $stockService->increase($p, $wh, '10.0000', '1000.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);

    // Create and post adjustment (counted 15 -> increased by 5)
    $adj = $adjService->create([
        'warehouse_id' => $wh,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Opname test state',
        'items' => [['product_id' => $p, 'counted_quantity' => '15.0000', 'unit_cost' => '1000.0000']],
    ]);
    $adjService->post($adj);

    // 1. Edit posted document redirects to show with flash error
    $resEdit = $this->get("/inventory/stock-adjustments/{$adj->id}/edit");
    $resEdit->assertRedirect("/inventory/stock-adjustments/{$adj->id}");
    $resEdit->assertSessionHas('error');

    // 2. Post on already posted status returns 303 error on status field for Inertia
    $resDoublePost = $this->withHeader('X-Inertia', 'true')
        ->from("/inventory/stock-adjustments/{$adj->id}")
        ->post("/inventory/stock-adjustments/{$adj->id}/post");
    $resDoublePost->assertStatus(303);
    $resDoublePost->assertSessionHasErrors('status');

    // 3. Consume stock so void cannot revert (consume 14 -> balance is 1, needs 5 to revert)
    $stockService->decrease($p, $wh, '14.0000', StockMovementType::DELIVERY, 'delivery_order', 1);

    // Void fails with error on quantity field and data remains unchanged
    $resVoid = $this->withHeader('X-Inertia', 'true')
        ->from("/inventory/stock-adjustments/{$adj->id}")
        ->post("/inventory/stock-adjustments/{$adj->id}/void", ['reason' => 'Revert fail']);
    $resVoid->assertStatus(303);
    $resVoid->assertSessionHasErrors('quantity');
    expect($adj->fresh()->status)->toBe(StockAdjustmentStatus::POSTED);
});

test('user with only stock_adjustments.view does not receive movements prop on show', function (): void {
    $wh = TestDataFactory::createWarehouse();
    $adj = StockAdjustment::create([
        'number' => 'SA/202610/00077',
        'warehouse_id' => $wh,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Prop test',
        'status' => StockAdjustmentStatus::DRAFT,
    ]);

    $onlyViewUser = userWithPermissions(['stock_adjustments.view']);
    $this->actingAs($onlyViewUser);

    $res = $this->get("/inventory/stock-adjustments/{$adj->id}");
    $res->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page->where('movements', null));
});
