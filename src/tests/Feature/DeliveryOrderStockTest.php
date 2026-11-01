<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
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
