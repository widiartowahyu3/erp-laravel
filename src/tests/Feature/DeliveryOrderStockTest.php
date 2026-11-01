<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Inventory\StockService;
use App\Services\Sales\DeliveryOrderService;
use App\Services\Sales\SalesOrderService;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDataFactory;

test('post delivery order deducts stock, sets moving avg unit_cost, and updates SO delivered_quantity and status', function (): void {
    $stockService = new StockService;
    $doService = new DeliveryOrderService($stockService);
    $soService = new SalesOrderService;

    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    // Pre-stock warehouse with 10 units @ 10,000.00
    DB::transaction(function () use ($stockService, $productId, $warehouseId): void {
        $stockService->increase(
            productId: $productId,
            warehouseId: $warehouseId,
            quantity: '10.0000',
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

    // Partial delivery: 4 units
    $do = $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [
            ['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '4.0000'],
        ],
    ]);

    $postedDo = $doService->post($do);

    expect($postedDo->status)->toBe(DeliveryOrderStatus::POSTED)
        ->and($postedDo->items->first()->unit_cost)->toBe('10000.0000') // Moving avg cost snapshot (S4)
        ->and($so->fresh()->status)->toBe(SalesOrderStatus::PARTIALLY_DELIVERED)
        ->and($soItem->fresh()->delivered_quantity)->toBe('4.0000');

    // Check stock balance after: 10 - 4 = 6
    $balance = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->first();
    expect($balance->quantity)->toBe('6.0000')
        ->and($balance->avg_cost)->toBe('10000.0000');

    // Check stock movement recorded
    $movement = StockMovement::where('source_type', StockService::SOURCE_DELIVERY_ORDER)
        ->where('source_id', $postedDo->id)
        ->first();
    expect($movement)->not->toBeNull()
        ->and($movement->movement_type)->toBe(StockMovementType::DELIVERY)
        ->and($movement->quantity)->toBe('-4.0000')
        ->and($movement->unit_cost)->toBe('10000.0000');

    // Complete delivery with remaining 6 units
    $do2 = $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [
            ['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '6.0000'],
        ],
    ]);
    $postedDo2 = $doService->post($do2);

    expect($postedDo2->status)->toBe(DeliveryOrderStatus::POSTED)
        ->and($so->fresh()->status)->toBe(SalesOrderStatus::DELIVERED)
        ->and($soItem->fresh()->delivered_quantity)->toBe('10.0000');

    $balanceFinal = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->first();
    expect($balanceFinal->quantity)->toBe('0.0000');
});

test('post delivery order throws InsufficientStockException and rolls back entire transaction if stock insufficient', function (): void {
    $stockService = new StockService;
    $doService = new DeliveryOrderService($stockService);
    $soService = new SalesOrderService;

    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    // Stock available: only 2 units
    DB::transaction(function () use ($stockService, $productId, $warehouseId): void {
        $stockService->increase(
            productId: $productId,
            warehouseId: $warehouseId,
            quantity: '2.0000',
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
            ['product_id' => $productId, 'quantity' => '5.0000', 'unit_price' => '15000.0000'],
        ],
    ]);
    $soService->approve($so);
    $soItem = $so->items->first();

    // Create DO for 5 units (available stock is only 2)
    $do = $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [
            ['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '5.0000'],
        ],
    ]);

    expect(fn () => $doService->post($do))
        ->toThrow(InsufficientStockException::class);

    // Rollback verification: DO remains DRAFT, stock unchanged, SO delivered_quantity = 0
    expect($do->fresh()->status)->toBe(DeliveryOrderStatus::DRAFT);
    expect($soItem->fresh()->delivered_quantity)->toBe('0.0000');
    expect(StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity'))->toBe('2.0000');
});

test('void posted delivery order restores stock at snapshot unit_cost and rolls back SO status', function (): void {
    $stockService = new StockService;
    $doService = new DeliveryOrderService($stockService);
    $soService = new SalesOrderService;

    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    // Initial stock: 10 units @ 10,000.00
    DB::transaction(function () use ($stockService, $productId, $warehouseId): void {
        $stockService->increase(
            productId: $productId,
            warehouseId: $warehouseId,
            quantity: '10.0000',
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

    $do = $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [
            ['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '10.0000'],
        ],
    ]);
    $postedDo = $doService->post($do);

    expect($so->fresh()->status)->toBe(SalesOrderStatus::DELIVERED);
    expect(StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity'))->toBe('0.0000');

    // Void the posted DO
    $voidedDo = $doService->void($postedDo, 'Barang rusak dalam perjalanan');

    expect($voidedDo->status)->toBe(DeliveryOrderStatus::VOID)
        ->and($voidedDo->void_reason)->toBe('Barang rusak dalam perjalanan');

    // SO delivered_quantity restored to 0, status rolls back to APPROVED
    expect($soItem->fresh()->delivered_quantity)->toBe('0.0000')
        ->and($so->fresh()->status)->toBe(SalesOrderStatus::APPROVED);

    // Stock balance restored to 10
    $balanceAfterVoid = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->first();
    expect($balanceAfterVoid->quantity)->toBe('10.0000')
        ->and($balanceAfterVoid->avg_cost)->toBe('10000.0000');

    // Reversal movement recorded with SOURCE_DELIVERY_ORDER_VOID
    $voidMovement = StockMovement::where('source_type', StockService::SOURCE_DELIVERY_ORDER_VOID)
        ->where('source_id', $voidedDo->id)
        ->first();
    expect($voidMovement)->not->toBeNull()
        ->and($voidMovement->movement_type)->toBe(StockMovementType::RECEIPT)
        ->and($voidMovement->quantity)->toBe('10.0000')
        ->and($voidMovement->unit_cost)->toBe('10000.0000'); // Restored with snapshot unit_cost (S5)
});

test('posting second draft DO claiming remaining quantity fails with over-delivery error and preserves state', function (): void {
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

    // Create two draft DOs each claiming full remaining (10.0000)
    $do1 = $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [
            ['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '10.0000'],
        ],
    ]);

    $do2 = $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [
            ['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '10.0000'],
        ],
    ]);

    // First DO posts successfully
    $postedDo1 = $doService->post($do1);
    expect($postedDo1->status)->toBe(DeliveryOrderStatus::POSTED)
        ->and($so->fresh()->status)->toBe(SalesOrderStatus::DELIVERED)
        ->and($soItem->fresh()->delivered_quantity)->toBe('10.0000');

    // Second DO post fails
    expect(fn () => $doService->post($do2))
        ->toThrow(BusinessRuleException::class, 'Kuantitas pengiriman baris ke-1 (10) melebihi sisa pesanan (0).');

    // DO2 remains draft, stock and delivered_quantity unchanged
    expect($do2->fresh()->status)->toBe(DeliveryOrderStatus::DRAFT)
        ->and($soItem->fresh()->delivered_quantity)->toBe('10.0000')
        ->and($so->fresh()->status)->toBe(SalesOrderStatus::DELIVERED);

    $balance = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->first();
    expect($balance->quantity)->toBe('10.0000'); // 20 initial - 10 from DO1
});

test('voiding posted DO and posting draft DO when SO is closed are rejected and leave stock unchanged', function (): void {
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

    // Partial delivery: 5 units
    $postedDo = $doService->post($doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [
            ['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '5.0000'],
        ],
    ]));

    // Another draft DO created before closing
    $draftDo = $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [
            ['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => '3.0000'],
        ],
    ]);

    // Close SO directly
    $so->update(['status' => SalesOrderStatus::CLOSED]);

    // 2. Voiding posted DO when SO is CLOSED must throw BusinessRuleException 409 field status
    try {
        $doService->void($postedDo, 'Batal retur');
        test()->fail('Expected BusinessRuleException on voiding DO for closed SO');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(409)
            ->and($e->field)->toBe('status');
    }
    expect($postedDo->fresh()->status)->toBe(DeliveryOrderStatus::POSTED);

    // 3. Posting draft DO when SO is CLOSED must throw BusinessRuleException 409/422 and remain draft
    try {
        $doService->post($draftDo);
        test()->fail('Expected BusinessRuleException on posting DO for closed SO');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(409)
            ->and($e->field)->toBe('status');
    }
    expect($draftDo->fresh()->status)->toBe(DeliveryOrderStatus::DRAFT);

    // Verify stock remains 15 (20 - 5 from posted DO)
    $balance = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->first();
    expect($balance->quantity)->toBe('15.0000');
});

test('post multi-product delivery order snapshots distinct moving avg unit_cost per line', function (): void {
    $stockService = new StockService;
    $doService = new DeliveryOrderService($stockService);
    $soService = new SalesOrderService;

    $customerId = TestDataFactory::createCustomer();
    $warehouseId = TestDataFactory::createWarehouse();
    $prodA = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);
    $prodB = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);

    // Product A has avg cost 12,000.00
    DB::transaction(function () use ($stockService, $prodA, $warehouseId): void {
        $stockService->increase(
            productId: $prodA,
            warehouseId: $warehouseId,
            quantity: '10.0000',
            unitCost: '12000.0000',
            type: StockMovementType::RECEIPT,
            sourceType: 'goods_receipt',
            sourceId: 1
        );
    });

    // Product B has avg cost 25,000.00
    DB::transaction(function () use ($stockService, $prodB, $warehouseId): void {
        $stockService->increase(
            productId: $prodB,
            warehouseId: $warehouseId,
            quantity: '5.0000',
            unitCost: '25000.0000',
            type: StockMovementType::RECEIPT,
            sourceType: 'goods_receipt',
            sourceId: 2
        );
    });

    $so = $soService->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-11-01',
        'items' => [
            ['product_id' => $prodA, 'quantity' => '4.0000', 'unit_price' => '15000.0000'],
            ['product_id' => $prodB, 'quantity' => '2.0000', 'unit_price' => '30000.0000'],
        ],
    ]);
    $soService->approve($so);
    $soItems = $so->items->keyBy('product_id');

    $do = $doService->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-11-01',
        'items' => [
            ['sales_order_item_id' => $soItems->get($prodA)->id, 'product_id' => $prodA, 'quantity' => '4.0000'],
            ['sales_order_item_id' => $soItems->get($prodB)->id, 'product_id' => $prodB, 'quantity' => '2.0000'],
        ],
    ]);

    $postedDo = $doService->post($do);

    expect($postedDo->status)->toBe(DeliveryOrderStatus::POSTED);

    $doItems = $postedDo->items->keyBy('product_id');
    expect($doItems->get($prodA)->unit_cost)->toBe('12000.0000')
        ->and($doItems->get($prodB)->unit_cost)->toBe('25000.0000');
});
