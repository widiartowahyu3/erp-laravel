<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\GoodsReceipt;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDataFactory;

test('creates purchase order and calculates line totals accurately', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();

    $uniqueSuffix = uniqid();
    $taxRateId = DB::table('tax_rates')->insertGetId([
        'code' => 'TX_'.$uniqueSuffix,
        'name' => 'PPN '.$uniqueSuffix,
        'rate' => '11.00',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = new PurchaseOrderService;
    $po = $service->create([
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '10.0000',
                'unit_price' => '20000.0000', // gross: 200,000.00
                'discount_amount' => '10000.00', // net: 190,000.00
                'tax_rate_id' => $taxRateId, // tax 11%: 20,900.00, total: 210,900.00
            ],
        ],
    ]);

    expect($po->status)->toBe(PurchaseOrderStatus::DRAFT);
    expect($po->subtotal)->toBe('200000.00');
    expect($po->discount_amount)->toBe('10000.00');
    expect($po->tax_amount)->toBe('20900.00');
    expect($po->total)->toBe('210900.00');
    expect($po->items)->toHaveCount(1);
    expect($po->items->first()?->line_total)->toBe('210900.00');
});

test('updates draft purchase order with new items and recalculates', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId1 = TestDataFactory::createProduct();
    $productId2 = TestDataFactory::createProduct();

    $service = new PurchaseOrderService;
    $po = $service->create([
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'items' => [
            [
                'product_id' => $productId1,
                'quantity' => '5.0000',
                'unit_price' => '10000.0000',
            ],
        ],
    ]);

    $updated = $service->update($po, [
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-31',
        'items' => [
            [
                'product_id' => $productId2,
                'quantity' => '2.0000',
                'unit_price' => '30000.0000',
            ],
        ],
    ]);

    expect($updated->total)->toBe('60000.00');
    expect($updated->items)->toHaveCount(1);
    expect($updated->items->first()?->product_id)->toBe($productId2);
});

test('update non-draft purchase order throws DocumentNotEditableException', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();

    $service = new PurchaseOrderService;
    $po = $service->create([
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
    ]);

    $service->approve($po);

    expect(fn () => $service->update($po, [
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '1.0000',
                'unit_price' => '10000.0000',
            ],
        ],
    ]))->toThrow(DocumentNotEditableException::class, 'Hanya draf pesanan pembelian yang dapat diubah.');
});

test('approves draft purchase order and sets approval audit fields', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();
    $userId = TestDataFactory::createUser();

    $service = new PurchaseOrderService;
    $po = $service->create([
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
    ]);

    $approved = $service->approve($po, $userId);

    expect($approved->status)->toBe(PurchaseOrderStatus::APPROVED);
    expect($approved->approved_by)->toBe($userId);
    expect($approved->approved_at)->not->toBeNull();
});

test('voids draft purchase order successfully with reason', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();
    $userId = TestDataFactory::createUser();

    $service = new PurchaseOrderService;
    $po = $service->create([
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
    ]);

    $voided = $service->void($po, 'Dibatalkan oleh manajemen', $userId);

    expect($voided->status)->toBe(PurchaseOrderStatus::VOID);
    expect($voided->void_reason)->toBe('Dibatalkan oleh manajemen');
    expect($voided->voided_by)->toBe($userId);
    expect($voided->voided_at)->not->toBeNull();
});

test('voiding purchase order fails if there is an active goods receipt', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();

    $service = new PurchaseOrderService;
    $po = $service->create([
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
    ]);

    $service->approve($po);

    GoodsReceipt::create([
        'number' => 'GR/202610/00099',
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-30',
        'status' => GoodsReceiptStatus::DRAFT,
    ]);

    try {
        $service->void($po, 'Mencoba batalkan');
        test()->fail('Expected BusinessRuleException was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getStatusCode())->toBe(409);
        expect($e->getField())->toBe('status');
        expect($e->getMessage())->toBe('Pesanan pembelian yang memiliki penerimaan barang aktif tidak dapat dibatalkan langsung.');
    }
});

test('closing purchase order is rejected with 409 if active draft goods receipt exists', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();

    $service = new PurchaseOrderService;
    $po = $service->create([
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
    ]);
    $service->approve($po);
    $po->update(['status' => PurchaseOrderStatus::PARTIALLY_RECEIVED]);

    GoodsReceipt::create([
        'number' => 'GR/202610/00088',
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-30',
        'status' => GoodsReceiptStatus::DRAFT,
    ]);

    try {
        $service->close($po);
        test()->fail('Expected BusinessRuleException was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getStatusCode())->toBe(409);
        expect($e->getField())->toBe('status');
        expect($e->getMessage())->toBe('Masih ada penerimaan barang berstatus draf. Posting atau batalkan terlebih dahulu.');
    }
});

test('closes purchase order from partially received or received status', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();

    $service = new PurchaseOrderService;
    $po = $service->create([
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
    ]);

    // From draft, close must throw InvalidStateTransitionException
    expect(fn () => $service->close($po))
        ->toThrow(InvalidStateTransitionException::class);

    $po->update(['status' => PurchaseOrderStatus::PARTIALLY_RECEIVED]);
    $closed = $service->close($po);
    expect($closed->status)->toBe(PurchaseOrderStatus::CLOSED);
});

test('purchase order service create and update reject line discount exceeding line gross with 422', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();

    $service = new PurchaseOrderService;

    // 1. Create with discount > gross (qty 2 * 1000 = 2000, discount 2000.01)
    try {
        $service->create([
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-10-30',
            'items' => [
                [
                    'product_id' => $productId,
                    'quantity' => '2.0000',
                    'unit_price' => '1000.0000',
                    'discount_amount' => '2000.01',
                ],
            ],
        ]);
        test()->fail('Expected BusinessRuleException on create was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getStatusCode())->toBe(422);
        expect($e->getField())->toBe('items.0.discount_amount');
        expect($e->getMessage())->toBe('Diskon tidak boleh melebihi nilai baris.');
    }

    // 2. Valid draft PO
    $po = $service->create([
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '2.0000',
                'unit_price' => '1000.0000',
                'discount_amount' => '500.00',
            ],
        ],
    ]);

    // 3. Update with discount > gross on line index 1
    try {
        $service->update($po, [
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-10-30',
            'items' => [
                [
                    'product_id' => $productId,
                    'quantity' => '1.0000',
                    'unit_price' => '1000.0000',
                    'discount_amount' => '500.00',
                ],
                [
                    'product_id' => $productId,
                    'quantity' => '3.0000',
                    'unit_price' => '500.0000', // gross 1500
                    'discount_amount' => '1500.01', // discount > gross
                ],
            ],
        ]);
        test()->fail('Expected BusinessRuleException on update was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getStatusCode())->toBe(422);
        expect($e->getField())->toBe('items.1.discount_amount');
        expect($e->getMessage())->toBe('Diskon tidak boleh melebihi nilai baris.');
    }
});

test('purchase order service update rejects closed purchase order with DocumentNotEditableException', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();

    $service = new PurchaseOrderService;
    $po = $service->create([
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-31',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '5.0000',
                'unit_price' => '10000.0000',
            ],
        ],
    ]);

    $po->update(['status' => PurchaseOrderStatus::CLOSED]);

    expect(fn () => $service->update($po, [
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-31',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '5.0000',
                'unit_price' => '10000.0000',
            ],
        ],
    ]))->toThrow(DocumentNotEditableException::class);
});
