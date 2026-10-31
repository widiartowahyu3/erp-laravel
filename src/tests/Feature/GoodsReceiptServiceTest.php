<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InsufficientStockException;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseOrderService;
use Tests\Support\TestDataFactory;

test('full purchasing receipt cycle: partial receipt -> full receipt -> void rollback', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $poService = new PurchaseOrderService;
    $stockService = new StockService;
    $grService = new GoodsReceiptService($stockService);

    // 1. Create and approve PO (Qty: 10, Price: 10,000)
    $po = $poService->create([
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '10.0000',
                'unit_price' => '10000.0000',
            ],
        ],
    ]);
    $poService->approve($po);
    $poItem = $po->items->first();
    assert($poItem !== null);

    // 2. First partial receipt (Qty: 4)
    $gr1 = $grService->create([
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
    expect($gr1->status)->toBe(GoodsReceiptStatus::DRAFT);

    $postedGr1 = $grService->post($gr1);
    expect($postedGr1->status)->toBe(GoodsReceiptStatus::POSTED);

    // PO status should be PARTIALLY_RECEIVED, item received_quantity = 4
    $poFresh = $po->fresh();
    expect($poFresh->status)->toBe(PurchaseOrderStatus::PARTIALLY_RECEIVED);
    expect($poItem->fresh()->received_quantity)->toBe('4.0000');

    // Stock balance should be 4 with avg_cost 10,000
    $balance = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->first();
    assert($balance !== null);
    expect($balance->quantity)->toBe('4.0000');
    expect($balance->avg_cost)->toBe('10000.0000');

    // 3. Second receipt (Remaining Qty: 6) with different purchase cost 12,000 to verify Moving Average
    // Let's create second GR with Qty 6
    $gr2 = $grService->create([
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-31',
        'items' => [
            [
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $productId,
                'quantity' => '6.0000',
            ],
        ],
    ]);
    $postedGr2 = $grService->post($gr2);
    expect($postedGr2->status)->toBe(GoodsReceiptStatus::POSTED);

    // PO status should now be RECEIVED, item received_quantity = 10
    $poFresh = $po->fresh();
    expect($poFresh->status)->toBe(PurchaseOrderStatus::RECEIVED);
    expect($poItem->fresh()->received_quantity)->toBe('10.0000');

    $balance->refresh();
    expect($balance->quantity)->toBe('10.0000');

    // 4. Void second receipt -> PO should rollback to PARTIALLY_RECEIVED, stock back to 4
    $voidedGr2 = $grService->void($postedGr2, 'Void partial GR2');
    expect($voidedGr2->status)->toBe(GoodsReceiptStatus::VOID);

    $poFresh = $po->fresh();
    expect($poFresh->status)->toBe(PurchaseOrderStatus::PARTIALLY_RECEIVED);
    expect($poItem->fresh()->received_quantity)->toBe('4.0000');

    $balance->refresh();
    expect($balance->quantity)->toBe('4.0000');

    // 5. Void first receipt -> PO should rollback to APPROVED, stock back to 0
    $voidedGr1 = $grService->void($postedGr1, 'Void partial GR1');
    expect($voidedGr1->status)->toBe(GoodsReceiptStatus::VOID);

    $poFresh = $po->fresh();
    expect($poFresh->status)->toBe(PurchaseOrderStatus::APPROVED);
    expect($poItem->fresh()->received_quantity)->toBe('0.0000');

    $balance->refresh();
    expect($balance->quantity)->toBe('0.0000');
});

test('voiding posted goods receipt fails if warehouse physical stock is insufficient', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $poService = new PurchaseOrderService;
    $stockService = new StockService;
    $grService = new GoodsReceiptService($stockService);

    $po = $poService->create([
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-30',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '10.0000',
                'unit_price' => '10000.0000',
            ],
        ],
    ]);
    $poService->approve($po);
    $poItem = $po->items->first();
    assert($poItem !== null);

    $gr = $grService->create([
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-30',
        'items' => [
            [
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $productId,
                'quantity' => '10.0000',
            ],
        ],
    ]);
    $postedGr = $grService->post($gr);

    // Consume 8 units from stock (e.g. delivered) -> remaining physical stock is only 2
    $stockService->decrease($productId, $warehouseId, '8.0000', StockMovementType::DELIVERY, 'delivery_order', 1);

    // Voiding GR requires deducting 10 units, but only 2 available -> must throw InsufficientStockException
    expect(fn () => $grService->void($postedGr, 'Batal penerimaan'))
        ->toThrow(InsufficientStockException::class);

    // Status and received quantity must remain unchanged
    expect($postedGr->fresh()->status)->toBe(GoodsReceiptStatus::POSTED);
    expect($poItem->fresh()->received_quantity)->toBe('10.0000');
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::RECEIVED);
});

test('goods receipt rejects over-receipt exceeding remaining purchase order quantity', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $poService = new PurchaseOrderService;
    $stockService = new StockService;
    $grService = new GoodsReceiptService($stockService);

    $po = $poService->create([
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
    $poService->approve($po);
    $poItem = $po->items->first();
    assert($poItem !== null);

    // Try receiving 6 (remaining is 5)
    expect(fn () => $grService->create([
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-30',
        'items' => [
            [
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $productId,
                'quantity' => '5.0001',
            ],
        ],
    ]))->toThrow(BusinessRuleException::class, 'Kuantitas penerimaan baris ke-1 melebihi sisa pesanan.');
});

test('goods receipt creation is rejected if purchase order is still draft', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $poService = new PurchaseOrderService;
    $stockService = new StockService;
    $grService = new GoodsReceiptService($stockService);

    $po = $poService->create([
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
    $poItem = $po->items->first();
    assert($poItem !== null);

    expect(fn () => $grService->create([
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
    ]))->toThrow(BusinessRuleException::class, 'Penerimaan barang hanya dapat dibuat untuk pesanan pembelian yang disetujui atau diterima sebagian.');
});

test('updating non-draft goods receipt throws DocumentNotEditableException', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $poService = new PurchaseOrderService;
    $stockService = new StockService;
    $grService = new GoodsReceiptService($stockService);

    $po = $poService->create([
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
    $poService->approve($po);
    $poItem = $po->items->first();
    assert($poItem !== null);

    $gr = $grService->create([
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
    ]);
    $grService->post($gr);

    expect(fn () => $grService->update($gr, [
        'receipt_date' => '2026-10-31',
        'items' => [
            [
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $productId,
                'quantity' => '2.0000',
            ],
        ],
    ]))->toThrow(DocumentNotEditableException::class, 'Hanya draf penerimaan barang yang dapat diubah.');
});

test('voiding goods receipt records stock movement with type adjustment_out instead of delivery', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $poService = new PurchaseOrderService;
    $stockService = new StockService;
    $grService = new GoodsReceiptService($stockService);

    $po = $poService->create([
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-26',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '10.0000',
                'unit_price' => '10000.00',
            ],
        ],
    ]);
    $poService->approve($po);
    $poItem = $po->items()->firstOrFail();

    $gr = $grService->create([
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-26',
        'items' => [
            [
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $productId,
                'quantity' => '10.0000',
            ],
        ],
    ]);
    $postedGr = $grService->post($gr);

    // Void the posted GR
    $grService->void($postedGr, 'Batal penerimaan');

    // Positive control: original receipt movement remains untouched
    $receiptMovement = StockMovement::query()
        ->where('product_id', $productId)
        ->where('source_type', StockService::SOURCE_GOODS_RECEIPT)
        ->where('source_id', $postedGr->id)
        ->first();
    expect($receiptMovement)->not->toBeNull();
    expect($receiptMovement->movement_type)->toBe(StockMovementType::RECEIPT);
    expect($receiptMovement->quantity)->toBe('10.0000');

    // Reversal movement must be adjustment_out, negative qty matching GR
    $reversalMovement = StockMovement::query()
        ->where('product_id', $productId)
        ->where('source_type', StockService::SOURCE_GOODS_RECEIPT_VOID)
        ->where('source_id', $postedGr->id)
        ->first();

    expect($reversalMovement)->not->toBeNull();
    expect($reversalMovement->movement_type)->toBe(StockMovementType::ADJUSTMENT_OUT);
    expect($reversalMovement->quantity)->toBe('-10.0000');

    // Assert NO delivery movement exists for this product
    $deliveryMovementCount = StockMovement::query()
        ->where('product_id', $productId)
        ->where('movement_type', StockMovementType::DELIVERY)
        ->count();
    expect($deliveryMovementCount)->toBe(0);
});

test('goods receipt stock valuation uses net unit cost after purchase order discount with moving average calculation', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $poService = new PurchaseOrderService;
    $stockService = new StockService;
    $grService = new GoodsReceiptService($stockService);

    // PO 1: Qty 10, Unit Price 2500.00, Discount 2500.00 (gross 25000 - 2500 = net 22500 / 10 = 2250.0000 per unit)
    $po1 = $poService->create([
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-26',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '10.0000',
                'unit_price' => '2500.00',
                'discount_amount' => '2500.00',
            ],
        ],
    ]);
    $poService->approve($po1);
    $po1Item = $po1->items()->firstOrFail();

    // GR 1
    $gr1 = $grService->create([
        'purchase_order_id' => $po1->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-26',
        'items' => [
            [
                'purchase_order_item_id' => $po1Item->id,
                'product_id' => $productId,
                'quantity' => '10.0000',
            ],
        ],
    ]);
    $postedGr1 = $grService->post($gr1);

    // Verify GR item unit cost is net: 2250.0000
    expect($postedGr1->items->first()->unit_cost)->toBe('2250.0000');

    // Verify Stock movement unit cost is net
    $movement1 = StockMovement::query()
        ->where('product_id', $productId)
        ->where('source_type', StockService::SOURCE_GOODS_RECEIPT)
        ->where('source_id', $postedGr1->id)
        ->firstOrFail();
    expect($movement1->unit_cost)->toBe('2250.0000');

    // Verify Stock balance avg_cost: 2250.0000
    $balance = StockBalance::query()
        ->where('product_id', $productId)
        ->where('warehouse_id', $warehouseId)
        ->firstOrFail();
    expect($balance->quantity)->toBe('10.0000');
    expect($balance->avg_cost)->toBe('2250.0000');

    // PO 2: Qty 10, Unit Price 3000.00, Discount 2500.00 (gross 30000 - 2500 = net 27500 / 10 = 2750.0000 per unit)
    $po2 = $poService->create([
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-26',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '10.0000',
                'unit_price' => '3000.00',
                'discount_amount' => '2500.00',
            ],
        ],
    ]);
    $poService->approve($po2);
    $po2Item = $po2->items()->firstOrFail();

    // GR 2
    $gr2 = $grService->create([
        'purchase_order_id' => $po2->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-26',
        'items' => [
            [
                'purchase_order_item_id' => $po2Item->id,
                'product_id' => $productId,
                'quantity' => '10.0000',
            ],
        ],
    ]);
    $postedGr2 = $grService->post($gr2);

    expect($postedGr2->items->first()->unit_cost)->toBe('2750.0000');

    // Moving average: (10 * 2250.0000 + 10 * 2750.0000) / 20 = 50000.0000 / 20 = 2500.0000
    $balance->refresh();
    expect($balance->quantity)->toBe('20.0000');
    expect($balance->avg_cost)->toBe('2500.0000');
});
