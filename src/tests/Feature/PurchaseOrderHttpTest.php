<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    config(['inertia.testing.ensure_pages_exist' => false]);
});

test('permission matrix enforces strict separation of duties on purchase order routes', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $po = PurchaseOrder::create([
        'number' => 'PO/202610/00088',
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'status' => PurchaseOrderStatus::DRAFT,
    ]);

    $viewUser = userWithPermissions(['purchase_orders.view']);
    $createUser = userWithPermissions(['purchase_orders.create']);
    $updateUser = userWithPermissions(['purchase_orders.update']);
    $approveUser = userWithPermissions(['purchase_orders.approve']);
    $voidUser = userWithPermissions(['purchase_orders.void']);

    // 1. Index & Show (purchase_orders.view)
    $this->actingAs($viewUser);
    $this->get('/purchasing/purchase-orders')->assertStatus(200);
    $this->get("/purchasing/purchase-orders/{$po->id}")->assertStatus(200);

    $this->flushSession();
    $this->actingAs($createUser);
    $this->get('/purchasing/purchase-orders')->assertStatus(403);
    $this->get("/purchasing/purchase-orders/{$po->id}")->assertStatus(403);

    // 2. Create & Store (purchase_orders.create)
    $this->get('/purchasing/purchase-orders/create')->assertStatus(200);
    $payload = [
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '5.0000',
                'unit_price' => '10000.0000',
            ],
        ],
    ];
    $this->post('/purchasing/purchase-orders', $payload)->assertStatus(302);

    $this->flushSession();
    $this->actingAs($viewUser);
    $this->get('/purchasing/purchase-orders/create')->assertStatus(403);
    $this->post('/purchasing/purchase-orders', $payload)->assertStatus(403);

    // 3. Edit & Update & Close (purchase_orders.update)
    $this->flushSession();
    $this->actingAs($updateUser);
    $this->get("/purchasing/purchase-orders/{$po->id}/edit")->assertStatus(200);
    $this->put("/purchasing/purchase-orders/{$po->id}", $payload)->assertStatus(302);

    $this->flushSession();
    $this->actingAs($viewUser);
    $this->get("/purchasing/purchase-orders/{$po->id}/edit")->assertStatus(403);
    $this->put("/purchasing/purchase-orders/{$po->id}", $payload)->assertStatus(403);
    $this->post("/purchasing/purchase-orders/{$po->id}/close")->assertStatus(403);

    // 4. Approve (purchase_orders.approve)
    $this->flushSession();
    $this->actingAs($approveUser);
    $this->post("/purchasing/purchase-orders/{$po->id}/approve")->assertStatus(302);

    $this->flushSession();
    $this->actingAs($viewUser);
    $this->post("/purchasing/purchase-orders/{$po->id}/approve")->assertStatus(403);

    // 5. Void (purchase_orders.void)
    $this->flushSession();
    $this->actingAs($voidUser);
    $this->post("/purchasing/purchase-orders/{$po->id}/void", ['reason' => 'Batal oleh finance'])->assertStatus(302);

    $this->flushSession();
    $this->actingAs($viewUser);
    $this->post("/purchasing/purchase-orders/{$po->id}/void", ['reason' => 'Batal'])->assertStatus(403);
});

test('role-based access: purchasing role can perform actions while admin role has read-only access', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $purchasingUser = userWithRole('purchasing');
    $adminUser = userWithRole('admin');

    // Admin can view index and show, but cannot mutate
    $this->actingAs($adminUser);
    $this->get('/purchasing/purchase-orders')->assertStatus(200);

    $payload = [
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '2.0000',
                'unit_price' => '10000.0000',
            ],
        ],
    ];
    $this->post('/purchasing/purchase-orders', $payload)->assertStatus(403);

    // Purchasing user can store
    $this->flushSession();
    $this->actingAs($purchasingUser);
    $res = $this->post('/purchasing/purchase-orders', $payload);
    $res->assertStatus(302);
});

test('full purchase order HTTP lifecycle: store -> show -> edit -> update -> approve -> close', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $purchasingUser = userWithRole('purchasing');
    $this->actingAs($purchasingUser);

    // 1. Store
    $resStore = $this->post('/purchasing/purchase-orders', [
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'expected_date' => '2026-11-05',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '10.0000',
                'unit_price' => '25000.0000',
            ],
        ],
    ]);

    $po = PurchaseOrder::where('supplier_id', $supplierId)->firstOrFail();
    $resStore->assertRedirect("/purchasing/purchase-orders/{$po->id}");
    expect($po->status)->toBe(PurchaseOrderStatus::DRAFT);
    expect($po->total)->toBe('250000.00');

    // 2. Show
    $this->get("/purchasing/purchase-orders/{$po->id}")
        ->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Purchasing/PurchaseOrders/Show')
            ->has('item')
        );

    // 3. Edit & Update
    $this->get("/purchasing/purchase-orders/{$po->id}/edit")->assertStatus(200);
    $resUpdate = $this->put("/purchasing/purchase-orders/{$po->id}", [
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '8.0000',
                'unit_price' => '25000.0000',
            ],
        ],
    ]);
    $resUpdate->assertRedirect("/purchasing/purchase-orders/{$po->id}");
    expect($po->fresh()->total)->toBe('200000.00');

    // 4. Approve
    $resApprove = $this->post("/purchasing/purchase-orders/{$po->id}/approve");
    $resApprove->assertStatus(302);
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::APPROVED);

    // 5. Edit approved document redirects to show with flash error
    $resEditApproved = $this->get("/purchasing/purchase-orders/{$po->id}/edit");
    $resEditApproved->assertRedirect("/purchasing/purchase-orders/{$po->id}");
    $resEditApproved->assertSessionHas('error');

    // 6. Close from partially received or received
    $po->update(['status' => PurchaseOrderStatus::RECEIVED]);
    $resClose = $this->post("/purchasing/purchase-orders/{$po->id}/close");
    $resClose->assertStatus(302);
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::CLOSED);
});

test('validation rejects invalid purchase order payloads on specific fields', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $purchasingUser = userWithRole('purchasing');
    $this->actingAs($purchasingUser);

    // Missing items
    $this->from('/purchasing/purchase-orders/create')
        ->post('/purchasing/purchase-orders', [
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-10-30',
            'items' => [],
        ])
        ->assertRedirect('/purchasing/purchase-orders/create')
        ->assertSessionHasErrors('items');

    // Negative unit price
    $this->from('/purchasing/purchase-orders/create')
        ->post('/purchasing/purchase-orders', [
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-10-30',
            'items' => [
                [
                    'product_id' => $productId,
                    'quantity' => '5.0000',
                    'unit_price' => '-1000.0000',
                ],
            ],
        ])
        ->assertRedirect('/purchasing/purchase-orders/create')
        ->assertSessionHasErrors('items.0.unit_price');

    // Expected date before order date
    $this->from('/purchasing/purchase-orders/create')
        ->post('/purchasing/purchase-orders', [
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-10-30',
            'expected_date' => '2026-10-25',
            'items' => [
                [
                    'product_id' => $productId,
                    'quantity' => '5.0000',
                    'unit_price' => '1000.0000',
                ],
            ],
        ])
        ->assertRedirect('/purchasing/purchase-orders/create')
        ->assertSessionHasErrors('expected_date');

    // Duplicate product_id in items
    $this->from('/purchasing/purchase-orders/create')
        ->post('/purchasing/purchase-orders', [
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-10-30',
            'items' => [
                [
                    'product_id' => $productId,
                    'quantity' => '1.0000',
                    'unit_price' => '1000.0000',
                ],
                [
                    'product_id' => $productId,
                    'quantity' => '2.0000',
                    'unit_price' => '1000.0000',
                ],
            ],
        ])
        ->assertRedirect('/purchasing/purchase-orders/create')
        ->assertSessionHasErrors('items.0.product_id');
});
