<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderItem;
use App\Models\StockBalance;
use App\Services\Inventory\StockService;
use App\Services\Sales\DeliveryOrderService;
use App\Services\Sales\SalesOrderService;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDataFactory;

test('create delivery order creates draft with gapless number and zero unit_cost', function (): void {
    $stockService = new StockService;
    $doService = new DeliveryOrderService($stockService);
    $soService = new SalesOrderService;

    $customerId = TestDataFactory::createCustomer(['address' => 'Jl. Merdeka No. 10']);
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    $so = $soService->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [
            ['product_id' => $productId, 'quantity' => '10.0000', 'unit_price' => '5000.0000'],
        ],
    ]);
    $soService->approve($so);

    $soItem = $so->items->first();

    $do = $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [
            [
                'sales_order_item_id' => $soItem->id,
                'product_id' => $productId,
                'quantity' => '4.0000',
            ],
        ],
    ]);

    expect($do)->toBeInstanceOf(DeliveryOrder::class)
        ->and($do->status)->toBe(DeliveryOrderStatus::DRAFT)
        ->and($do->number)->toStartWith('DO/202611/')
        ->and($do->shipping_address)->toBe('Jl. Merdeka No. 10')
        ->and($do->items)->toHaveCount(1)
        ->and($do->items[0]->quantity)->toBe('4.0000')
        ->and($do->items[0]->unit_cost)->toBe('0.0000');
});

test('create delivery order rejects unapproved SO, mismatch customer/warehouse, and quantity exceeding remaining', function (): void {
    $stockService = new StockService;
    $doService = new DeliveryOrderService($stockService);
    $soService = new SalesOrderService;

    $customerId = TestDataFactory::createCustomer();
    $otherCustomerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $otherWarehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    $so = $soService->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [
            ['product_id' => $productId, 'quantity' => '5.0000', 'unit_price' => '5000.0000'],
        ],
    ]);
    $soItem = $so->items->first();

    // 1. Unapproved SO (draft)
    expect(fn () => $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '2.0000']],
    ]))->toThrow(BusinessRuleException::class, 'Surat jalan hanya dapat dibuat untuk pesanan penjualan yang disetujui atau terkirim sebagian.');

    $soService->approve($so);

    // 2. Customer mismatch
    expect(fn () => $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $otherCustomerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '2.0000']],
    ]))->toThrow(BusinessRuleException::class, 'Pelanggan pada surat jalan tidak cocok dengan pesanan penjualan.');

    // 3. Warehouse mismatch
    expect(fn () => $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $otherWarehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '2.0000']],
    ]))->toThrow(BusinessRuleException::class, 'Gudang pada surat jalan tidak cocok dengan pesanan penjualan.');

    // 4. Quantity exceeds remaining (5.0000 available, requested 6.0000)
    expect(fn () => $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '6.0000']],
    ]))->toThrow(BusinessRuleException::class, 'Kuantitas pengiriman baris ke-1 melebihi sisa pesanan penjualan.');
});

test('update delivery order updates draft DO and throws DocumentNotEditableException when not draft', function (): void {
    $stockService = new StockService;
    $doService = new DeliveryOrderService($stockService);
    $soService = new SalesOrderService;

    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    $so = $soService->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [['product_id' => $productId, 'quantity' => '10.0000', 'unit_price' => '5000.0000']],
    ]);
    $soService->approve($so);
    $soItem = $so->items->first();

    $do = $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'shipping_address' => 'Alamat Awal',
        'items' => [['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '3.0000']],
    ]);

    $updated = $doService->update($do, [
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-02',
        'shipping_address' => 'Alamat Baru',
        'items' => [['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '4.0000']],
    ]);

    expect($updated->shipping_address)->toBe('Alamat Baru')
        ->and($updated->items->first()->quantity)->toBe('4.0000');

    $do->update(['status' => DeliveryOrderStatus::VOID]);
    expect(fn () => $doService->update($do, [
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-02',
        'items' => [['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '1.0000']],
    ]))->toThrow(DocumentNotEditableException::class, 'Hanya draf surat jalan yang dapat diubah.');
});

test('void delivery order on draft marks void with reason without modifying stock', function (): void {
    $stockService = new StockService;
    $doService = new DeliveryOrderService($stockService);
    $soService = new SalesOrderService;

    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    $so = $soService->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [['product_id' => $productId, 'quantity' => '10.0000', 'unit_price' => '5000.0000']],
    ]);
    $soService->approve($so);
    $soItem = $so->items->first();

    $do = $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '3.0000']],
    ]);

    expect(fn () => $doService->void($do, '  '))
        ->toThrow(BusinessRuleException::class, 'Alasan pembatalan wajib diisi.');

    $voided = $doService->void($do, 'Dibatalkan staf');
    expect($voided->status)->toBe(DeliveryOrderStatus::VOID)
        ->and($voided->void_reason)->toBe('Dibatalkan staf');
});

test('create and update reject duplicate sales order item lines at service level', function (): void {
    $stockService = new StockService;
    $doService = new DeliveryOrderService($stockService);
    $soService = new SalesOrderService;

    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    $so = $soService->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [
            ['product_id' => $productId, 'quantity' => '10.0000', 'unit_price' => '5000.0000'],
        ],
    ]);
    $soService->approve($so);
    $soItem = $so->items->first();

    // a) create() with duplicate sales_order_item_id
    try {
        $doService->create([
            'sales_order_id' => $so->id,
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'delivery_date' => '2026-11-01',
            'items' => [
                ['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '3.0000'],
                ['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '3.0000'],
            ],
        ]);
        test()->fail('Expected BusinessRuleException on duplicate sales_order_item_id in create');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(422)
            ->and($e->field)->toBe('items');
    }

    // create valid DO for testing update
    $do = $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [
            ['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '3.0000'],
        ],
    ]);

    // b) update() with duplicate sales_order_item_id
    try {
        $doService->update($do, [
            'delivery_date' => '2026-11-02',
            'items' => [
                ['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '2.0000'],
                ['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '2.0000'],
            ],
        ]);
        test()->fail('Expected BusinessRuleException on duplicate sales_order_item_id in update');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(422)
            ->and($e->field)->toBe('items');
    }
});

test('post rejects DO with duplicate sales order item lines or aggregated quantity exceeding remaining', function (): void {
    $stockService = new StockService;
    $doService = new DeliveryOrderService($stockService);
    $soService = new SalesOrderService;

    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    DB::transaction(function () use ($stockService, $productId, $warehouseId): void {
        $stockService->increase(
            productId: $productId,
            warehouseId: $warehouseId,
            quantity: '20.0000',
            unitCost: '10000.0000',
            type: StockMovementType::RECEIPT,
            sourceType: 'goods_receipt',
            sourceId: 1
        );
    });

    $so = $soService->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [
            ['product_id' => $productId, 'quantity' => '10.0000', 'unit_price' => '15000.0000'],
        ],
    ]);
    $soService->approve($so);
    $soItem = $so->items->first();

    // Direct creation of draft DO and duplicate DO items
    $do = DeliveryOrder::create([
        'number' => 'DO/TEST/001',
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'status' => DeliveryOrderStatus::DRAFT,
    ]);

    // Two lines for the same sales_order_item_id, 6.0000 each on remaining 10.0000 (total 12 > 10)
    DeliveryOrderItem::create([
        'delivery_order_id' => $do->id,
        'line_no' => 1,
        'sales_order_item_id' => $soItem->id,
        'product_id' => $productId,
        'quantity' => '6.0000',
        'unit_cost' => '0.0000',
    ]);
    DeliveryOrderItem::create([
        'delivery_order_id' => $do->id,
        'line_no' => 2,
        'sales_order_item_id' => $soItem->id,
        'product_id' => $productId,
        'quantity' => '6.0000',
        'unit_cost' => '0.0000',
    ]);

    // c) post() must throw BusinessRuleException, stock not decreased, delivered_quantity unchanged, DO status remains DRAFT
    try {
        $doService->post($do);
        test()->fail('Expected BusinessRuleException on post with duplicate sales_order_item_id');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(422)
            ->and($e->field)->toBe('items');
    }

    expect($do->fresh()->status)->toBe(DeliveryOrderStatus::DRAFT)
        ->and($soItem->fresh()->delivered_quantity)->toBe('0.0000')
        ->and($so->fresh()->status)->toBe(SalesOrderStatus::APPROVED);

    $balance = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->first();
    expect($balance->quantity)->toBe('20.0000');
});
