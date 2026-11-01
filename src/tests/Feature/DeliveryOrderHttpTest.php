<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Models\DeliveryOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    config(['inertia.testing.ensure_pages_exist' => false]);
});

test('permission matrix enforces strict separation of duties across delivery order routes', function (): void {
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $so = SalesOrder::create([
        'number' => 'SO/202611/00091',
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'status' => SalesOrderStatus::APPROVED,
        'subtotal' => '100000.00',
        'total' => '100000.00',
    ]);

    $soItem = SalesOrderItem::create([
        'sales_order_id' => $so->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '10.0000',
        'delivered_quantity' => '0.0000',
        'unit_price' => '10000.0000',
        'line_total' => '100000.00',
    ]);

    $do = DeliveryOrder::create([
        'number' => 'DO/202611/00091',
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'status' => DeliveryOrderStatus::DRAFT,
    ]);

    $viewUser = userWithPermissions(['delivery_orders.view']);
    $createUser = userWithPermissions(['delivery_orders.create']);
    $updateUser = userWithPermissions(['delivery_orders.update']);
    $postUser = userWithPermissions(['delivery_orders.post']);
    $voidUser = userWithPermissions(['delivery_orders.void']);

    // 1. Index & Show (delivery_orders.view)
    $this->actingAs($viewUser);
    $this->get('/sales/delivery-orders')->assertStatus(200);
    $this->get("/sales/delivery-orders/{$do->id}")->assertStatus(200);

    $this->flushSession();
    $this->actingAs($createUser);
    $this->get('/sales/delivery-orders')->assertStatus(403);
    $this->get("/sales/delivery-orders/{$do->id}")->assertStatus(403);

    // 2. Create & Store (delivery_orders.create)
    $this->get("/sales/delivery-orders/create?sales_order_id={$so->id}")->assertStatus(200);
    $payload = [
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [
            [
                'sales_order_item_id' => $soItem->id,
                'product_id' => $productId,
                'quantity' => '3.0000',
            ],
        ],
    ];
    $this->post('/sales/delivery-orders', $payload)->assertStatus(302);

    $this->flushSession();
    $this->actingAs($viewUser);
    $this->get("/sales/delivery-orders/create?sales_order_id={$so->id}")->assertStatus(403);
    $this->post('/sales/delivery-orders', $payload)->assertStatus(403);

    // 3. Edit & Update (delivery_orders.update)
    $this->flushSession();
    $this->actingAs($updateUser);
    $this->get("/sales/delivery-orders/{$do->id}/edit")->assertStatus(200);
    $this->put("/sales/delivery-orders/{$do->id}", $payload)->assertStatus(302);

    $this->flushSession();
    $this->actingAs($viewUser);
    $this->get("/sales/delivery-orders/{$do->id}/edit")->assertStatus(403);
    $this->put("/sales/delivery-orders/{$do->id}", $payload)->assertStatus(403);

    // 4. Post (delivery_orders.post)
    $this->flushSession();
    $this->actingAs($viewUser);
    $this->post("/sales/delivery-orders/{$do->id}/post")->assertStatus(403);

    $this->flushSession();
    $this->actingAs($postUser);
    // Needs stock to post
    DB::transaction(function () use ($productId, $warehouseId): void {
        app(StockService::class)->increase($productId, $warehouseId, '20.0000', '5000.0000', StockMovementType::RECEIPT, 'test', 1);
    });
    $this->post("/sales/delivery-orders/{$do->id}/post")->assertStatus(302);

    // 5. Void (delivery_orders.void)
    $this->flushSession();
    $this->actingAs($viewUser);
    $this->post("/sales/delivery-orders/{$do->id}/void", ['reason' => 'Batal kirim barang'])->assertStatus(403);

    $this->flushSession();
    $this->actingAs($voidUser);
    $this->post("/sales/delivery-orders/{$do->id}/void", ['reason' => 'Batal kirim barang'])->assertStatus(302);
});

test('role-based access: warehouse role can manage and deliver goods while sales role only views', function (): void {
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $warehouseUser = userWithRole('warehouse');
    $salesUser = userWithRole('sales');

    $so = SalesOrder::create([
        'number' => 'SO/202611/00092',
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'status' => SalesOrderStatus::APPROVED,
        'subtotal' => '50000.00',
        'total' => '50000.00',
    ]);

    $soItem = SalesOrderItem::create([
        'sales_order_id' => $so->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '5.0000',
        'delivered_quantity' => '0.0000',
        'unit_price' => '10000.0000',
        'line_total' => '50000.00',
    ]);

    $payload = [
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [
            [
                'sales_order_item_id' => $soItem->id,
                'product_id' => $productId,
                'quantity' => '5.0000',
            ],
        ],
    ];

    // Sales user can view index, but cannot create or store delivery order
    $this->actingAs($salesUser);
    $this->get('/sales/delivery-orders')->assertStatus(200);
    $this->post('/sales/delivery-orders', $payload)->assertStatus(403);

    // Warehouse user can create and store delivery order
    $this->flushSession();
    $this->actingAs($warehouseUser);
    $this->post('/sales/delivery-orders', $payload)->assertStatus(302);
});

test('delivery order index returns inertia response with filters, pagination, and search', function (): void {
    $user = userWithPermissions(['delivery_orders.view']);
    $this->actingAs($user);

    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();

    $so = SalesOrder::create([
        'number' => 'SO/202611/00093',
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'status' => SalesOrderStatus::APPROVED,
    ]);

    DeliveryOrder::create([
        'number' => 'DO/202611/00093',
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'status' => DeliveryOrderStatus::DRAFT,
        'notes' => 'DO pertama pengiriman pagi',
    ]);

    $response = $this->get('/sales/delivery-orders?search=pertama');
    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Sales/DeliveryOrders/Index')
        ->has('items.data', 1)
        ->has('customers')
        ->has('warehouses')
        ->has('statuses')
    );
});

test('delivery order create requires sales_order_id and validates eligibility', function (): void {
    $user = userWithPermissions(['delivery_orders.create']);
    $this->actingAs($user);

    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    // 1. Missing sales_order_id param
    $resMissing = $this->get('/sales/delivery-orders/create');
    $resMissing->assertRedirect(route('sales.sales-orders.index'));

    // 2. Draft SO (not approved yet)
    $draftSo = SalesOrder::create([
        'number' => 'SO/202611/00094',
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'status' => SalesOrderStatus::DRAFT,
    ]);
    $resDraft = $this->get("/sales/delivery-orders/create?sales_order_id={$draftSo->id}");
    $resDraft->assertRedirect(route('sales.sales-orders.index'));

    // 3. Fully delivered SO
    $fullyDeliveredSo = SalesOrder::create([
        'number' => 'SO/202611/00095',
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'status' => SalesOrderStatus::DELIVERED,
    ]);
    SalesOrderItem::create([
        'sales_order_id' => $fullyDeliveredSo->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '5.0000',
        'delivered_quantity' => '5.0000',
        'unit_price' => '10000.0000',
        'line_total' => '50000.00',
    ]);
    $resFullyDelivered = $this->get("/sales/delivery-orders/create?sales_order_id={$fullyDeliveredSo->id}");
    $resFullyDelivered->assertRedirect(route('sales.sales-orders.index'));

    // 4. Approved SO with remaining items renders Inertia create page
    $approvedSo = SalesOrder::create([
        'number' => 'SO/202611/00096',
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'status' => SalesOrderStatus::APPROVED,
    ]);
    SalesOrderItem::create([
        'sales_order_id' => $approvedSo->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '10.0000',
        'delivered_quantity' => '0.0000',
        'unit_price' => '10000.0000',
        'line_total' => '100000.00',
    ]);
    $resEligible = $this->get("/sales/delivery-orders/create?sales_order_id={$approvedSo->id}");
    $resEligible->assertStatus(200);
    $resEligible->assertInertia(fn (Assert $page) => $page
        ->component('Sales/DeliveryOrders/Create')
        ->has('salesOrder')
        ->has('eligibleItems', 1)
    );
});

test('full delivery order HTTP lifecycle: store -> show -> edit -> update -> post -> void', function (): void {
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    // Seed stock
    DB::transaction(function () use ($productId, $warehouseId): void {
        app(StockService::class)->increase($productId, $warehouseId, '20.0000', '5000.0000', StockMovementType::RECEIPT, 'test', 1);
    });

    $warehouseUser = userWithRole('warehouse');
    $this->actingAs($warehouseUser);

    $so = SalesOrder::create([
        'number' => 'SO/202611/00097',
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'status' => SalesOrderStatus::APPROVED,
        'subtotal' => '100000.00',
        'total' => '100000.00',
    ]);

    $soItem = SalesOrderItem::create([
        'sales_order_id' => $so->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '10.0000',
        'delivered_quantity' => '0.0000',
        'unit_price' => '10000.0000',
        'line_total' => '100000.00',
    ]);

    // 1. Store
    $resStore = $this->post('/sales/delivery-orders', [
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'shipping_address' => 'Jl. Pengiriman No. 12',
        'items' => [
            [
                'sales_order_item_id' => $soItem->id,
                'product_id' => $productId,
                'quantity' => '4.0000',
            ],
        ],
    ]);

    $do = DeliveryOrder::where('sales_order_id', $so->id)->firstOrFail();
    $resStore->assertRedirect("/sales/delivery-orders/{$do->id}");
    expect($do->status)->toBe(DeliveryOrderStatus::DRAFT);

    // 2. Show
    $this->get("/sales/delivery-orders/{$do->id}")
        ->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales/DeliveryOrders/Show')
            ->has('item')
        );

    // 3. Edit & Update
    $this->get("/sales/delivery-orders/{$do->id}/edit")->assertStatus(200);
    $resUpdate = $this->put("/sales/delivery-orders/{$do->id}", [
        'delivery_date' => '2026-11-01',
        'notes' => 'Catatan revisi rute pengiriman',
        'items' => [
            [
                'sales_order_item_id' => $soItem->id,
                'product_id' => $productId,
                'quantity' => '6.0000',
            ],
        ],
    ]);
    $resUpdate->assertRedirect("/sales/delivery-orders/{$do->id}");
    expect($do->fresh()->items->first()?->quantity)->toBe('6.0000');

    // 4. Post
    $resPost = $this->post("/sales/delivery-orders/{$do->id}/post");
    $resPost->assertStatus(302);
    expect($do->fresh()->status)->toBe(DeliveryOrderStatus::POSTED);
    expect($so->fresh()->status)->toBe(SalesOrderStatus::PARTIALLY_DELIVERED);

    // 5. Void
    $resVoid = $this->post("/sales/delivery-orders/{$do->id}/void", ['reason' => 'Barang rusak dalam armada, batal kirim']);
    $resVoid->assertStatus(302);
    expect($do->fresh()->status)->toBe(DeliveryOrderStatus::VOID);
    expect($so->fresh()->status)->toBe(SalesOrderStatus::APPROVED);
});

test('delivery order validation rejects future delivery date and invalid item quantities', function (): void {
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $user = userWithPermissions(['delivery_orders.create']);
    $this->actingAs($user);

    $so = SalesOrder::create([
        'number' => 'SO/202611/00098',
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'status' => SalesOrderStatus::APPROVED,
    ]);

    $soItem = SalesOrderItem::create([
        'sales_order_id' => $so->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '10.0000',
        'delivered_quantity' => '0.0000',
        'unit_price' => '10000.0000',
        'line_total' => '100000.00',
    ]);

    // Future date
    $this->post('/sales/delivery-orders', [
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => date('Y-m-d', strtotime('+2 days')),
        'items' => [
            [
                'sales_order_item_id' => $soItem->id,
                'product_id' => $productId,
                'quantity' => '1.0000',
            ],
        ],
    ])->assertSessionHasErrors(['delivery_date']);

    // Non-positive quantity
    $this->post('/sales/delivery-orders', [
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [
            [
                'sales_order_item_id' => $soItem->id,
                'product_id' => $productId,
                'quantity' => '0.0000',
            ],
        ],
    ])->assertSessionHasErrors(['items.0.quantity']);
});

test('delivery order store accepts trimmed quantity without decimal places', function () {
    $user = userWithPermissions(['delivery_orders.create']);
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['selling_price' => '10000.00']);

    $so = SalesOrder::create([
        'number' => 'SO/202611/00098',
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'status' => SalesOrderStatus::APPROVED,
        'subtotal' => '660000.00',
        'tax_amount' => '0.00',
        'discount_amount' => '0.00',
        'total' => '660000.00',
    ]);
    $soItem = SalesOrderItem::create([
        'sales_order_id' => $so->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '100.0000',
        'delivered_quantity' => '0.0000',
        'unit_price' => '10000.0000',
        'line_total' => '1000000.00',
    ]);

    $this->actingAs($user)
        ->post('/sales/delivery-orders', [
            'sales_order_id' => $so->id,
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'delivery_date' => '2026-11-01',
            'items' => [
                [
                    'sales_order_item_id' => $soItem->id,
                    'product_id' => $productId,
                    'quantity' => '66',
                ],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertStatus(302);

    $do = DeliveryOrder::with('items')->latest('id')->firstOrFail();
    expect($do->items->first()->quantity)->toBe('66.0000');
});
