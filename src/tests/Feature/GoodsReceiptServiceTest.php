<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateTransitionException;
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

test('goods receipt posting is rejected with 409 if purchase order is closed or void', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $poService = new PurchaseOrderService;
    $stockService = new StockService;
    $grService = new GoodsReceiptService($stockService);

    // 1. PO Closed
    $po1 = $poService->create([
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-26',
        'items' => [
            ['product_id' => $productId, 'quantity' => '10.0000', 'unit_price' => '1000.00'],
        ],
    ]);
    $poService->approve($po1);
    $po1Item = $po1->items()->firstOrFail();

    $grDraft1 = $grService->create([
        'purchase_order_id' => $po1->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-26',
        'items' => [
            ['purchase_order_item_id' => $po1Item->id, 'product_id' => $productId, 'quantity' => '5.0000'],
        ],
    ]);

    // Force PO1 to CLOSED
    $po1->update(['status' => PurchaseOrderStatus::CLOSED]);

    $po1StatusBefore = $po1->fresh()->status;
    $grDraft1StatusBefore = $grDraft1->fresh()->status;
    $stockQty1Before = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity') ?? '0.0000';
    $movementsCount1Before = StockMovement::count();

    try {
        $grService->post($grDraft1);
        test()->fail('Expected BusinessRuleException was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getStatusCode())->toBe(409);
        expect($e->getField())->toBe('status');
    }

    // Invariant assertions: state, stock, and movements must remain unchanged
    expect($po1->fresh()->status)->toBe($po1StatusBefore);
    expect($grDraft1->fresh()->status)->toBe($grDraft1StatusBefore);
    expect(StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity') ?? '0.0000')->toBe($stockQty1Before);
    expect(StockMovement::count())->toBe($movementsCount1Before);

    // 2. PO Void
    $po2 = $poService->create([
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-26',
        'items' => [
            ['product_id' => $productId, 'quantity' => '10.0000', 'unit_price' => '1000.00'],
        ],
    ]);
    $poService->approve($po2);
    $po2Item = $po2->items()->firstOrFail();

    $grDraft2 = $grService->create([
        'purchase_order_id' => $po2->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-26',
        'items' => [
            ['purchase_order_item_id' => $po2Item->id, 'product_id' => $productId, 'quantity' => '5.0000'],
        ],
    ]);

    $po2->update(['status' => PurchaseOrderStatus::VOID]);

    $po2StatusBefore = $po2->fresh()->status;
    $grDraft2StatusBefore = $grDraft2->fresh()->status;
    $stockQty2Before = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity') ?? '0.0000';
    $movementsCount2Before = StockMovement::count();

    try {
        $grService->post($grDraft2);
        test()->fail('Expected BusinessRuleException was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getStatusCode())->toBe(409);
        expect($e->getField())->toBe('status');
    }

    expect($po2->fresh()->status)->toBe($po2StatusBefore);
    expect($grDraft2->fresh()->status)->toBe($grDraft2StatusBefore);
    expect(StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity') ?? '0.0000')->toBe($stockQty2Before);
    expect(StockMovement::count())->toBe($movementsCount2Before);
});

test('voiding posted goods receipt is rejected with 409 if purchase order is closed, but voiding draft is allowed', function (): void {
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
            ['product_id' => $productId, 'quantity' => '10.0000', 'unit_price' => '1000.00'],
        ],
    ]);
    $poService->approve($po);
    $poItem = $po->items()->firstOrFail();

    $gr1 = $grService->create([
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-26',
        'items' => [
            ['purchase_order_item_id' => $poItem->id, 'product_id' => $productId, 'quantity' => '5.0000'],
        ],
    ]);
    $postedGr1 = $grService->post($gr1);

    // Create draft GR2
    $gr2 = $grService->create([
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-26',
        'items' => [
            ['purchase_order_item_id' => $poItem->id, 'product_id' => $productId, 'quantity' => '3.0000'],
        ],
    ]);

    // Force PO to closed
    $po->update(['status' => PurchaseOrderStatus::CLOSED]);

    // Void draft GR2 on closed PO must succeed
    $voidedDraft = $grService->void($gr2, 'Void draft on closed PO');
    expect($voidedDraft->status)->toBe(GoodsReceiptStatus::VOID);

    // Void posted GR1 on closed PO must be rejected with 409
    $gr1StatusBefore = $postedGr1->fresh()->status;
    $stockQtyBefore = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity') ?? '0.0000';
    $movementsCountBefore = StockMovement::count();

    try {
        $grService->void($postedGr1, 'Void posted on closed PO');
        test()->fail('Expected BusinessRuleException was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getStatusCode())->toBe(409);
        expect($e->getField())->toBe('status');
        expect($e->getMessage())->toBe('Penerimaan barang pada pesanan pembelian yang sudah ditutup tidak dapat dibatalkan.');
    }

    expect($postedGr1->fresh()->status)->toBe($gr1StatusBefore);
    expect(StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity') ?? '0.0000')->toBe($stockQtyBefore);
    expect(StockMovement::count())->toBe($movementsCountBefore);
});

test('updating draft goods receipt is rejected with 409 if purchase order is closed', function (): void {
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
            ['product_id' => $productId, 'quantity' => '10.0000', 'unit_price' => '1000.00'],
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
            ['purchase_order_item_id' => $poItem->id, 'product_id' => $productId, 'quantity' => '5.0000'],
        ],
    ]);

    // Force PO to closed
    $po->update(['status' => PurchaseOrderStatus::CLOSED]);

    $poStatusBefore = $po->fresh()->status;
    $grStatusBefore = $gr->fresh()->status;
    $stockBefore = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity') ?? '0.0000';
    $movementsBefore = StockMovement::count();

    try {
        $grService->update($gr, [
            'receipt_date' => '2026-10-26',
            'items' => [
                ['purchase_order_item_id' => $poItem->id, 'product_id' => $productId, 'quantity' => '6.0000'],
            ],
        ]);
        test()->fail('Expected BusinessRuleException was not thrown on update with closed PO.');
    } catch (BusinessRuleException $e) {
        expect($e->getStatusCode())->toBe(409);
        expect($e->getField())->toBe('status');
        expect($e->getMessage())->toBe('Penerimaan barang hanya dapat diubah untuk pesanan pembelian yang disetujui atau diterima sebagian.');
    }

    expect($po->fresh()->status)->toBe($poStatusBefore);
    expect($gr->fresh()->status)->toBe($grStatusBefore);
    expect(StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity') ?? '0.0000')->toBe($stockBefore);
    expect(StockMovement::count())->toBe($movementsBefore);
});

test('complete workflow: PO qty 10 -> GR1 posted 4 -> GR2 draft 3 -> close PO rejected -> void GR2 draft -> close PO succeeds -> void GR1 rejected -> stock stays 4 and PO closed', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $poService = new PurchaseOrderService;
    $stockService = new StockService;
    $grService = new GoodsReceiptService($stockService);

    // 1. PO qty 10
    $po = $poService->create([
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-26',
        'items' => [
            ['product_id' => $productId, 'quantity' => '10.0000', 'unit_price' => '1000.00'],
        ],
    ]);
    $poService->approve($po);
    $poItem = $po->items()->firstOrFail();

    // 2. GR1 posted qty 4 -> PO becomes partially_received
    $gr1 = $grService->create([
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-26',
        'items' => [
            ['purchase_order_item_id' => $poItem->id, 'product_id' => $productId, 'quantity' => '4.0000'],
        ],
    ]);
    $postedGr1 = $grService->post($gr1);
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::PARTIALLY_RECEIVED);

    $balance = StockBalance::query()
        ->where('product_id', $productId)
        ->where('warehouse_id', $warehouseId)
        ->firstOrFail();
    expect($balance->quantity)->toBe('4.0000');

    // 3. GR2 draft qty 3
    $gr2Draft = $grService->create([
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-26',
        'items' => [
            ['purchase_order_item_id' => $poItem->id, 'product_id' => $productId, 'quantity' => '3.0000'],
        ],
    ]);
    expect($gr2Draft->status)->toBe(GoodsReceiptStatus::DRAFT);

    // 4. Close PO rejected because GR2 is draft
    try {
        $poService->close($po->fresh());
        test()->fail('Expected BusinessRuleException was not thrown on close with draft GR.');
    } catch (BusinessRuleException $e) {
        expect($e->getStatusCode())->toBe(409);
        expect($e->getField())->toBe('status');
        expect($e->getMessage())->toBe('Masih ada penerimaan barang berstatus draf. Posting atau batalkan terlebih dahulu.');
    }

    // 5. Void GR2 draft
    $voidedGr2 = $grService->void($gr2Draft, 'Batal draft GR2');
    expect($voidedGr2->status)->toBe(GoodsReceiptStatus::VOID);

    // 6. Close PO succeeds
    $closedPo = $poService->close($po->fresh());
    expect($closedPo->status)->toBe(PurchaseOrderStatus::CLOSED);

    // 7. Void GR1 rejected because PO is closed
    try {
        $grService->void($postedGr1, 'Batal posted GR1 on closed PO');
        test()->fail('Expected BusinessRuleException was not thrown on voiding posted GR on closed PO.');
    } catch (BusinessRuleException $e) {
        expect($e->getStatusCode())->toBe(409);
        expect($e->getField())->toBe('status');
        expect($e->getMessage())->toBe('Penerimaan barang pada pesanan pembelian yang sudah ditutup tidak dapat dibatalkan.');
    }

    // 8. Stock stays 4 and PO stays closed
    $balance->refresh();
    expect($balance->quantity)->toBe('4.0000');
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::CLOSED);
});

test('GoodsReceiptService post checks GR status before PO status when PO is closed', function (): void {
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $poService = app(PurchaseOrderService::class);
    $grService = app(GoodsReceiptService::class);

    $po = $poService->create([
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-31',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => '10.0000',
                'unit_price' => '10000.0000',
                'discount_percent' => '0.00',
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
        'receipt_date' => '2026-10-31',
        'items' => [
            [
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $productId,
                'quantity' => '10.0000',
            ],
        ],
    ]);

    $postedGr = $grService->post($gr);
    expect($postedGr->status)->toBe(GoodsReceiptStatus::POSTED);

    // Close PO
    $closedPo = $poService->close($po->fresh());
    expect($closedPo->status)->toBe(PurchaseOrderStatus::CLOSED);

    // Attempting to post the already posted GR on closed PO must throw InvalidStateTransitionException
    expect(fn () => $grService->post($postedGr))
        ->toThrow(InvalidStateTransitionException::class);

    // Expand 9b: posting GR draft on closed PO => 409 field status and no stock changes
    // Create draft GR before forcing PO to closed
    $poDraft = $poService->create([
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
    $poService->approve($poDraft);
    $poDraftItem = $poDraft->items->first();
    assert($poDraftItem !== null);

    $draftGrOnClosedPo = $grService->create([
        'purchase_order_id' => $poDraft->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-31',
        'items' => [
            [
                'purchase_order_item_id' => $poDraftItem->id,
                'product_id' => $productId,
                'quantity' => '1.0000',
            ],
        ],
    ]);

    // Force PO to closed
    $poDraft->update(['status' => PurchaseOrderStatus::CLOSED]);

    $stockBefore = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity');
    $movementsBefore = StockMovement::count();

    try {
        $grService->post($draftGrOnClosedPo);
        test()->fail('Expected BusinessRuleException on posting draft GR on closed PO was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getStatusCode())->toBe(409);
        expect($e->getField())->toBe('status');
    }

    expect($draftGrOnClosedPo->fresh()->status)->toBe(GoodsReceiptStatus::DRAFT);
    expect(StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity'))->toBe($stockBefore);
    expect(StockMovement::count())->toBe($movementsBefore);
});
