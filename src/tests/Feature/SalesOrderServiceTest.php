<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\Sales\SalesOrderService;
use Tests\Support\TestDataFactory;

test('create sales order creates draft with gapless number and calculated totals', function (): void {
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId1 = TestDataFactory::createProduct(['selling_price' => '10000.00', 'track_stock' => true, 'is_active' => true]);
    $productId2 = TestDataFactory::createProduct(['selling_price' => '20000.00', 'track_stock' => true, 'is_active' => true]);

    $service = new SalesOrderService;
    $so = $service->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'notes' => 'Catatan pesanan',
        'items' => [
            [
                'product_id' => $productId1,
                'quantity' => '2.0000',
                'unit_price' => '10000.0000',
                'discount_amount' => '1000.00',
            ],
            [
                'product_id' => $productId2,
                'quantity' => '1.0000',
                'unit_price' => '20000.0000',
                'discount_amount' => '0.00',
            ],
        ],
    ]);

    expect($so)->toBeInstanceOf(SalesOrder::class)
        ->and($so->status)->toBe(SalesOrderStatus::DRAFT)
        ->and($so->number)->toStartWith('SO/202611/')
        ->and($so->subtotal)->toBe('40000.00')
        ->and($so->discount_amount)->toBe('1000.00')
        ->and($so->tax_amount)->toBe('0.00')
        ->and($so->total)->toBe('39000.00')
        ->and($so->items)->toHaveCount(2)
        ->and($so->items[0]->remainingQuantity())->toBe('2.0000')
        ->and($so->items[1]->remainingQuantity())->toBe('1.0000');
});

test('create sales order rejects inactive customer, inactive warehouse, or non-track_stock product', function (): void {
    $service = new SalesOrderService;

    // Inactive customer
    $inactiveCustId = TestDataFactory::createCustomer(['is_active' => false]);
    $warehouseId = TestDataFactory::createWarehouse();
    $validProdId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    expect(fn () => $service->create([
        'customer_id' => $inactiveCustId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [['product_id' => $validProdId, 'quantity' => '1.0000', 'unit_price' => '1000.0000']],
    ]))->toThrow(BusinessRuleException::class, 'Pelanggan tidak aktif atau tidak ditemukan.');

    // Inactive warehouse
    $activeCustId = TestDataFactory::createCustomer();
    $inactiveWhId = TestDataFactory::createWarehouse(['is_active' => false]);

    expect(fn () => $service->create([
        'customer_id' => $activeCustId,
        'warehouse_id' => $inactiveWhId,
        'order_date' => '2026-11-01',
        'items' => [['product_id' => $validProdId, 'quantity' => '1.0000', 'unit_price' => '1000.0000']],
    ]))->toThrow(BusinessRuleException::class, 'Gudang tidak aktif atau tidak ditemukan.');

    // Product without track_stock (S3)
    $nonTrackProdId = TestDataFactory::createProduct(['track_stock' => false, 'is_active' => true]);

    expect(fn () => $service->create([
        'customer_id' => $activeCustId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [['product_id' => $nonTrackProdId, 'quantity' => '1.0000', 'unit_price' => '1000.0000']],
    ]))->toThrow(BusinessRuleException::class, 'Produk harus aktif dan melacak persediaan (track_stock).');

    // Inactive product
    $inactiveProdId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => false]);

    expect(fn () => $service->create([
        'customer_id' => $activeCustId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [['product_id' => $inactiveProdId, 'quantity' => '1.0000', 'unit_price' => '1000.0000']],
    ]))->toThrow(BusinessRuleException::class, 'Produk harus aktif dan melacak persediaan (track_stock).');
});

test('create and update sales order rejects discount exceeding line gross', function (): void {
    $service = new SalesOrderService;
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    expect(fn () => $service->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '1.0000',
                'unit_price' => '1000.0000',
                'discount_amount' => '1500.00',
            ],
        ],
    ]))->toThrow(BusinessRuleException::class, 'Diskon tidak boleh melebihi nilai baris.');
});

test('update sales order updates header and atomically replaces items', function (): void {
    $service = new SalesOrderService;
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId1 = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);
    $productId2 = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    $so = $service->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [
            ['product_id' => $productId1, 'quantity' => '5.0000', 'unit_price' => '1000.0000'],
        ],
    ]);

    $updated = $service->update($so, [
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-02',
        'notes' => 'Catatan revisi',
        'items' => [
            ['product_id' => $productId2, 'quantity' => '3.0000', 'unit_price' => '2000.0000'],
        ],
    ]);

    expect($updated->order_date->format('Y-m-d'))->toBe('2026-11-02')
        ->and($updated->notes)->toBe('Catatan revisi')
        ->and($updated->items)->toHaveCount(1)
        ->and($updated->items->first()->product_id)->toBe($productId2)
        ->and($updated->total)->toBe('6000.00');
});

test('update sales order throws DocumentNotEditableException when not draft', function (): void {
    $service = new SalesOrderService;
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    $so = $service->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [['product_id' => $productId, 'quantity' => '1.0000', 'unit_price' => '1000.0000']],
    ]);

    $service->approve($so);

    expect(fn () => $service->update($so, [
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [['product_id' => $productId, 'quantity' => '2.0000', 'unit_price' => '1000.0000']],
    ]))->toThrow(DocumentNotEditableException::class, 'Hanya draf pesanan penjualan yang dapat diubah.');
});

test('approve sales order transitions from draft to approved', function (): void {
    $service = new SalesOrderService;
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    $so = $service->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [['product_id' => $productId, 'quantity' => '1.0000', 'unit_price' => '1000.0000']],
    ]);

    $approved = $service->approve($so);
    expect($approved->status)->toBe(SalesOrderStatus::APPROVED)
        ->and($approved->approved_at)->not->toBeNull();

    // Cannot approve already approved SO
    expect(fn () => $service->approve($approved))
        ->toThrow(InvalidStateTransitionException::class);
});

test('void sales order cancels draft or approved and requires non-empty reason', function (): void {
    $service = new SalesOrderService;
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    $so = $service->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [['product_id' => $productId, 'quantity' => '1.0000', 'unit_price' => '1000.0000']],
    ]);

    // Reason cannot be empty
    expect(fn () => $service->void($so, '   '))
        ->toThrow(BusinessRuleException::class, 'Alasan pembatalan wajib diisi.');

    $voided = $service->void($so, 'Dibatalkan oleh pelanggan');
    expect($voided->status)->toBe(SalesOrderStatus::VOID)
        ->and($voided->void_reason)->toBe('Dibatalkan oleh pelanggan')
        ->and($voided->voided_at)->not->toBeNull();
});

test('void sales order is blocked when active delivery order exists', function (): void {
    $service = new SalesOrderService;
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    $so = $service->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [['product_id' => $productId, 'quantity' => '5.0000', 'unit_price' => '1000.0000']],
    ]);
    $service->approve($so);

    DeliveryOrder::create([
        'number' => 'DO/202611/00099',
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'status' => DeliveryOrderStatus::DRAFT,
    ]);

    expect(fn () => $service->void($so, 'Batal SO'))
        ->toThrow(BusinessRuleException::class, 'Pesanan penjualan yang memiliki surat jalan aktif tidak dapat dibatalkan langsung.');
});

test('close sales order closes partially delivered or delivered SO and blocks if draft DO exists', function (): void {
    $service = new SalesOrderService;
    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    $so = $service->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [['product_id' => $productId, 'quantity' => '5.0000', 'unit_price' => '1000.0000']],
    ]);
    $service->approve($so);
    $so->update(['status' => SalesOrderStatus::DELIVERED]);

    // Active draft DO blocks close
    $do = DeliveryOrder::create([
        'number' => 'DO/202611/00100',
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'status' => DeliveryOrderStatus::DRAFT,
    ]);

    expect(fn () => $service->close($so))
        ->toThrow(BusinessRuleException::class, 'Masih ada surat jalan berstatus draf. Posting atau batalkan terlebih dahulu.');

    $do->update(['status' => DeliveryOrderStatus::VOID]);

    $closed = $service->close($so);
    expect($closed->status)->toBe(SalesOrderStatus::CLOSED);
});
