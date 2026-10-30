<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    config(['inertia.testing.ensure_pages_exist' => false]);
});

test('permission matrix enforces strict separation of duties across goods receipt routes', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $po = PurchaseOrder::create([
        'number' => 'PO/202610/00071',
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'status' => PurchaseOrderStatus::APPROVED,
        'subtotal' => '100000.00',
        'total' => '100000.00',
    ]);

    $poItem = PurchaseOrderItem::create([
        'purchase_order_id' => $po->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '10.0000',
        'received_quantity' => '0.0000',
        'unit_price' => '10000.0000',
        'line_total' => '100000.00',
    ]);

    $gr = GoodsReceipt::create([
        'number' => 'GR/202610/00071',
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-30',
        'status' => GoodsReceiptStatus::DRAFT,
    ]);

    $viewUser = userWithPermissions(['goods_receipts.view']);
    $createUser = userWithPermissions(['goods_receipts.create']);
    $updateUser = userWithPermissions(['goods_receipts.update']);
    $postUser = userWithPermissions(['goods_receipts.post']);
    $voidUser = userWithPermissions(['goods_receipts.void']);

    // 1. Index & Show (goods_receipts.view)
    $this->actingAs($viewUser);
    $this->get('/purchasing/goods-receipts')->assertStatus(200);
    $this->get("/purchasing/goods-receipts/{$gr->id}")->assertStatus(200);

    $this->flushSession();
    $this->actingAs($createUser);
    $this->get('/purchasing/goods-receipts')->assertStatus(403);
    $this->get("/purchasing/goods-receipts/{$gr->id}")->assertStatus(403);

    // 2. Create & Store (goods_receipts.create)
    $this->get("/purchasing/goods-receipts/create?purchase_order_id={$po->id}")->assertStatus(200);
    $payload = [
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-30',
        'items' => [
            [
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $productId,
                'quantity' => '3.0000',
            ],
        ],
    ];
    $this->post('/purchasing/goods-receipts', $payload)->assertStatus(302);

    $this->flushSession();
    $this->actingAs($viewUser);
    $this->get("/purchasing/goods-receipts/create?purchase_order_id={$po->id}")->assertStatus(403);
    $this->post('/purchasing/goods-receipts', $payload)->assertStatus(403);

    // 3. Edit & Update (goods_receipts.update)
    $this->flushSession();
    $this->actingAs($updateUser);
    $this->get("/purchasing/goods-receipts/{$gr->id}/edit")->assertStatus(200);
    $this->put("/purchasing/goods-receipts/{$gr->id}", $payload)->assertStatus(302);

    $this->flushSession();
    $this->actingAs($viewUser);
    $this->get("/purchasing/goods-receipts/{$gr->id}/edit")->assertStatus(403);
    $this->put("/purchasing/goods-receipts/{$gr->id}", $payload)->assertStatus(403);

    // 4. Post (goods_receipts.post)
    $this->flushSession();
    $this->actingAs($postUser);
    $this->post("/purchasing/goods-receipts/{$gr->id}/post")->assertStatus(302);

    $this->flushSession();
    $this->actingAs($viewUser);
    $this->post("/purchasing/goods-receipts/{$gr->id}/post")->assertStatus(403);

    // 5. Void (goods_receipts.void)
    $this->flushSession();
    $this->actingAs($voidUser);
    $this->post("/purchasing/goods-receipts/{$gr->id}/void", ['reason' => 'Batal oleh gudang'])->assertStatus(302);

    $this->flushSession();
    $this->actingAs($viewUser);
    $this->post("/purchasing/goods-receipts/{$gr->id}/void", ['reason' => 'Batal'])->assertStatus(403);
});

test('role-based access: warehouse role can receive goods while purchasing role only views', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $warehouseUser = userWithRole('warehouse');
    $purchasingUser = userWithRole('purchasing');

    $po = PurchaseOrder::create([
        'number' => 'PO/202610/00072',
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'status' => PurchaseOrderStatus::APPROVED,
        'subtotal' => '50000.00',
        'total' => '50000.00',
    ]);

    $poItem = PurchaseOrderItem::create([
        'purchase_order_id' => $po->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '5.0000',
        'received_quantity' => '0.0000',
        'unit_price' => '10000.0000',
        'line_total' => '50000.00',
    ]);

    $payload = [
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-30',
        'items' => [
            [
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $productId,
                'quantity' => '5.0000',
            ],
        ],
    ];

    // Purchasing user can view index, but cannot create or store goods receipt
    $this->actingAs($purchasingUser);
    $this->get('/purchasing/goods-receipts')->assertStatus(200);
    $this->post('/purchasing/goods-receipts', $payload)->assertStatus(403);

    // Warehouse user can store goods receipt
    $this->flushSession();
    $this->actingAs($warehouseUser);
    $this->post('/purchasing/goods-receipts', $payload)->assertStatus(302);
});

test('full goods receipt HTTP lifecycle: store -> show -> edit -> update -> post -> void', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $warehouseUser = userWithRole('warehouse');
    $this->actingAs($warehouseUser);

    $po = PurchaseOrder::create([
        'number' => 'PO/202610/00073',
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'status' => PurchaseOrderStatus::APPROVED,
        'subtotal' => '100000.00',
        'total' => '100000.00',
    ]);

    $poItem = PurchaseOrderItem::create([
        'purchase_order_id' => $po->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '10.0000',
        'received_quantity' => '0.0000',
        'unit_price' => '10000.0000',
        'line_total' => '100000.00',
    ]);

    // 1. Store
    $resStore = $this->post('/purchasing/goods-receipts', [
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-30',
        'items' => [
            [
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $productId,
                'quantity' => '4.0000',
            ],
        ],
    ]);

    $gr = GoodsReceipt::where('purchase_order_id', $po->id)->firstOrFail();
    $resStore->assertRedirect("/purchasing/goods-receipts/{$gr->id}");
    expect($gr->status)->toBe(GoodsReceiptStatus::DRAFT);

    // 2. Show
    $this->get("/purchasing/goods-receipts/{$gr->id}")
        ->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Purchasing/GoodsReceipts/Show')
            ->has('item')
        );

    // 3. Edit & Update
    $this->get("/purchasing/goods-receipts/{$gr->id}/edit")->assertStatus(200);
    $resUpdate = $this->put("/purchasing/goods-receipts/{$gr->id}", [
        'receipt_date' => '2026-10-30',
        'notes' => 'Catatan diupdate',
        'items' => [
            [
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $productId,
                'quantity' => '6.0000',
            ],
        ],
    ]);
    $resUpdate->assertRedirect("/purchasing/goods-receipts/{$gr->id}");
    expect($gr->fresh()->items->first()?->quantity)->toBe('6.0000');

    // 4. Post
    $resPost = $this->post("/purchasing/goods-receipts/{$gr->id}/post");
    $resPost->assertStatus(302);
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::POSTED);
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::PARTIALLY_RECEIVED);

    // 5. Edit non-draft redirects to show with flash error
    $resEditPosted = $this->get("/purchasing/goods-receipts/{$gr->id}/edit");
    $resEditPosted->assertRedirect("/purchasing/goods-receipts/{$gr->id}");
    $resEditPosted->assertSessionHas('error');

    // 6. Void
    $resVoid = $this->post("/purchasing/goods-receipts/{$gr->id}/void", ['reason' => 'Salah terima barang']);
    $resVoid->assertStatus(302);
    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::VOID);
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::APPROVED);
});

test('validation rejects invalid goods receipt payloads on specific fields', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $warehouseUser = userWithRole('warehouse');
    $this->actingAs($warehouseUser);

    $po = PurchaseOrder::create([
        'number' => 'PO/202610/00074',
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'status' => PurchaseOrderStatus::APPROVED,
        'subtotal' => '50000.00',
        'total' => '50000.00',
    ]);

    $poItem = PurchaseOrderItem::create([
        'purchase_order_id' => $po->id,
        'line_no' => 1,
        'product_id' => $productId,
        'quantity' => '5.0000',
        'received_quantity' => '0.0000',
        'unit_price' => '10000.0000',
        'line_total' => '50000.00',
    ]);

    // Future receipt date
    $this->from('/purchasing/goods-receipts/create')
        ->post('/purchasing/goods-receipts', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'receipt_date' => '2099-01-01',
            'items' => [
                [
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $productId,
                    'quantity' => '2.0000',
                ],
            ],
        ])
        ->assertRedirect('/purchasing/goods-receipts/create')
        ->assertSessionHasErrors('receipt_date');

    // Negative/zero quantity
    $this->from('/purchasing/purchase-orders/create')
        ->post('/purchasing/goods-receipts', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'receipt_date' => '2026-10-30',
            'items' => [
                [
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $productId,
                    'quantity' => '0.0000',
                ],
            ],
        ])
        ->assertSessionHasErrors('items.0.quantity');
});
