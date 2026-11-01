<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    config(['inertia.testing.ensure_pages_exist' => false]);
});

test('permission matrix enforces strict separation of duties on sales order routes', function (): void {
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    $so = SalesOrder::create([
        'number' => 'SO/202611/00088',
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'status' => SalesOrderStatus::DRAFT,
    ]);

    $viewUser = userWithPermissions(['sales_orders.view']);
    $createUser = userWithPermissions(['sales_orders.create']);
    $updateUser = userWithPermissions(['sales_orders.update']);
    $approveUser = userWithPermissions(['sales_orders.approve']);
    $voidUser = userWithPermissions(['sales_orders.void']);

    // 1. Index & Show (sales_orders.view)
    $this->actingAs($viewUser);
    $this->get('/sales/sales-orders')->assertStatus(200);
    $this->get("/sales/sales-orders/{$so->id}")->assertStatus(200);

    $this->flushSession();
    $this->actingAs($createUser);
    $this->get('/sales/sales-orders')->assertStatus(403);
    $this->get("/sales/sales-orders/{$so->id}")->assertStatus(403);

    // 2. Create & Store (sales_orders.create)
    $this->get('/sales/sales-orders/create')->assertStatus(200);
    $payload = [
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '5.0000',
                'unit_price' => '10000.0000',
            ],
        ],
    ];
    $this->post('/sales/sales-orders', $payload)->assertStatus(302);

    $this->flushSession();
    $this->actingAs($viewUser);
    $this->get('/sales/sales-orders/create')->assertStatus(403);
    $this->post('/sales/sales-orders', $payload)->assertStatus(403);

    // 3. Edit & Update (sales_orders.update)
    $this->flushSession();
    $this->actingAs($updateUser);
    $this->get("/sales/sales-orders/{$so->id}/edit")->assertStatus(200);
    $this->put("/sales/sales-orders/{$so->id}", $payload)->assertStatus(302);

    $this->flushSession();
    $this->actingAs($viewUser);
    $this->get("/sales/sales-orders/{$so->id}/edit")->assertStatus(403);
    $this->put("/sales/sales-orders/{$so->id}", $payload)->assertStatus(403);

    // 4. Approve (sales_orders.approve)
    $this->flushSession();
    $this->actingAs($viewUser);
    $this->post("/sales/sales-orders/{$so->id}/approve")->assertStatus(403);

    $this->flushSession();
    $this->actingAs($approveUser);
    $this->post("/sales/sales-orders/{$so->id}/approve")->assertStatus(302);
    expect($so->fresh()->status)->toBe(SalesOrderStatus::APPROVED);

    // 5. Void (sales_orders.void)
    $this->flushSession();
    $this->actingAs($viewUser);
    $this->post("/sales/sales-orders/{$so->id}/void", ['reason' => 'Batal'])->assertStatus(403);

    $this->flushSession();
    $this->actingAs($voidUser);
    $this->post("/sales/sales-orders/{$so->id}/void", ['reason' => 'Batal alasan lengkap'])->assertStatus(302);
    expect($so->fresh()->status)->toBe(SalesOrderStatus::VOID);
});

test('sales order index returns inertia response with filters, pagination, and search', function (): void {
    $user = userWithPermissions(['sales_orders.view']);
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();

    SalesOrder::create([
        'number' => 'SO/202611/00001',
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'status' => SalesOrderStatus::DRAFT,
        'subtotal' => '10000.00',
        'total' => '10000.00',
    ]);

    $this->actingAs($user)
        ->get('/sales/sales-orders?search=SO/202611')
        ->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales/SalesOrders/Index')
            ->has('items.data')
            ->has('customers')
            ->has('warehouses')
            ->has('statuses')
        );
});

test('sales order store validates payload rigorously', function (): void {
    $user = userWithPermissions(['sales_orders.create']);
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $this->actingAs($user);

    // Missing customer_id
    $this->from('/sales/sales-orders/create')
        ->post('/sales/sales-orders', [
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-11-01',
            'items' => [['product_id' => $productId, 'quantity' => '1.0000', 'unit_price' => '1000.0000']],
        ])
        ->assertRedirect('/sales/sales-orders/create')
        ->assertSessionHasErrors('customer_id');

    // Missing items
    $this->from('/sales/sales-orders/create')
        ->post('/sales/sales-orders', [
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-11-01',
            'items' => [],
        ])
        ->assertRedirect('/sales/sales-orders/create')
        ->assertSessionHasErrors('items');

    // Duplicate product_id in items
    $this->from('/sales/sales-orders/create')
        ->post('/sales/sales-orders', [
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-11-01',
            'items' => [
                ['product_id' => $productId, 'quantity' => '1.0000', 'unit_price' => '1000.0000'],
                ['product_id' => $productId, 'quantity' => '2.0000', 'unit_price' => '1000.0000'],
            ],
        ])
        ->assertRedirect('/sales/sales-orders/create')
        ->assertSessionHasErrors('items.0.product_id');
});

test('sales order edit and update rejects non-draft sales orders', function (): void {
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $user = userWithPermissions(['sales_orders.update']);

    $so = SalesOrder::create([
        'number' => 'SO/202611/00099',
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'status' => SalesOrderStatus::APPROVED,
        'subtotal' => '50000.00',
        'total' => '50000.00',
    ]);

    // Edit non-draft redirects to show with error
    $this->actingAs($user)
        ->get("/sales/sales-orders/{$so->id}/edit")
        ->assertRedirect("/sales/sales-orders/{$so->id}");

    // Put update on non-draft throws DocumentNotEditableException (409)
    $this->actingAs($user)
        ->from("/sales/sales-orders/{$so->id}/edit")
        ->put("/sales/sales-orders/{$so->id}", [
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-11-01',
            'items' => [
                ['product_id' => $productId, 'quantity' => '5.0000', 'unit_price' => '10000.0000'],
            ],
        ])
        ->assertStatus(409);
});
