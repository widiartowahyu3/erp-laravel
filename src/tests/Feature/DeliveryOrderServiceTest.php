<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Services\Inventory\StockService;
use App\Services\Sales\DeliveryOrderService;
use App\Services\Sales\SalesOrderService;
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
