<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\TestDataFactory;

test('stock service executes exact reference chain of increases and decreases', function (): void {
    $userId = TestDataFactory::createUser();
    $user = User::findOrFail($userId);
    $this->actingAs($user);

    $productId = TestDataFactory::createProduct(['sku' => 'SKU-CHAIN-01', 'track_stock' => true]);
    $warehouseId = TestDataFactory::createWarehouse(['code' => 'WH-CHAIN-01']);

    $service = new StockService;

    // 1. Terima 10 @ 1000 -> qty 10.0000 avg 1000.0000
    $m1 = $service->increase($productId, $warehouseId, '10', '1000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 101);
    expect($m1->quantity)->toBe('10.0000')
        ->and($m1->unit_cost)->toBe('1000.0000')
        ->and($m1->balance_after)->toBe('10.0000')
        ->and($m1->avg_cost_after)->toBe('1000.0000')
        ->and($m1->movement_type)->toBe(StockMovementType::RECEIPT)
        ->and($m1->source_type)->toBe(StockService::SOURCE_GOODS_RECEIPT)
        ->and($m1->source_id)->toBe(101)
        ->and($m1->created_by)->toBe($userId);

    $b1 = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->firstOrFail();
    expect($b1->quantity)->toBe('10.0000')->and($b1->avg_cost)->toBe('1000.0000');

    // 2. Terima 5 @ 1300 -> qty 15.0000 avg 1100.0000
    $m2 = $service->increase($productId, $warehouseId, '5', '1300', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 102);
    expect($m2->quantity)->toBe('5.0000')
        ->and($m2->unit_cost)->toBe('1300.0000')
        ->and($m2->balance_after)->toBe('15.0000')
        ->and($m2->avg_cost_after)->toBe('1100.0000');

    $b2 = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->firstOrFail();
    expect($b2->quantity)->toBe('15.0000')->and($b2->avg_cost)->toBe('1100.0000');

    // 3. Keluar 4 -> unit_cost 1100.0000, qty 11.0000, avg 1100.0000, movement.quantity -4.0000
    $m3 = $service->decrease($productId, $warehouseId, '4', StockMovementType::DELIVERY, 'delivery_order', 201);
    expect($m3->quantity)->toBe('-4.0000')
        ->and($m3->unit_cost)->toBe('1100.0000')
        ->and($m3->balance_after)->toBe('11.0000')
        ->and($m3->avg_cost_after)->toBe('1100.0000')
        ->and($m3->movement_type)->toBe(StockMovementType::DELIVERY);

    $b3 = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->firstOrFail();
    expect($b3->quantity)->toBe('11.0000')->and($b3->avg_cost)->toBe('1100.0000');

    // 4. Terima 3 @ 1000 -> qty 14.0000 avg 1078.5714
    $m4 = $service->increase($productId, $warehouseId, '3', '1000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 103);
    expect($m4->quantity)->toBe('3.0000')
        ->and($m4->unit_cost)->toBe('1000.0000')
        ->and($m4->balance_after)->toBe('14.0000')
        ->and($m4->avg_cost_after)->toBe('1078.5714');

    $b4 = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->firstOrFail();
    expect($b4->quantity)->toBe('14.0000')->and($b4->avg_cost)->toBe('1078.5714');

    // 5. Keluar 14 -> qty 0.0000 avg tetap 1078.5714
    $m5 = $service->decrease($productId, $warehouseId, '14', StockMovementType::DELIVERY, 'delivery_order', 202);
    expect($m5->quantity)->toBe('-14.0000')
        ->and($m5->unit_cost)->toBe('1078.5714')
        ->and($m5->balance_after)->toBe('0.0000')
        ->and($m5->avg_cost_after)->toBe('1078.5714');

    $b5 = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->firstOrFail();
    expect($b5->quantity)->toBe('0.0000')->and($b5->avg_cost)->toBe('1078.5714');

    // 6. Terima 2 @ 500 -> avg 500.0000
    $m6 = $service->increase($productId, $warehouseId, '2', '500', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 104);
    expect($m6->quantity)->toBe('2.0000')
        ->and($m6->unit_cost)->toBe('500.0000')
        ->and($m6->balance_after)->toBe('2.0000')
        ->and($m6->avg_cost_after)->toBe('500.0000');

    $b6 = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->firstOrFail();
    expect($b6->quantity)->toBe('2.0000')->and($b6->avg_cost)->toBe('500.0000');
});

test('decrease exceeding available stock throws InsufficientStockException without modifying stock or movements', function (): void {
    $userId = TestDataFactory::createUser();
    $this->actingAs(User::findOrFail($userId));

    $productId = TestDataFactory::createProduct(['sku' => 'SKU-OVER-01', 'track_stock' => true]);
    $warehouseId = TestDataFactory::createWarehouse(['code' => 'WH-OVER-01']);

    $service = new StockService;
    $service->increase($productId, $warehouseId, '5.0000', '1000.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);

    $movementsBefore = StockMovement::count();
    $balanceBefore = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->firstOrFail();

    $thrown = false;
    try {
        $service->decrease($productId, $warehouseId, '6.0000', StockMovementType::DELIVERY, 'delivery_order', 2);
    } catch (InsufficientStockException $e) {
        $thrown = true;
        expect($e->getField())->toBe('quantity')
            ->and($e->getMessage())->toContain('SKU-OVER-01')
            ->and($e->getMessage())->toContain('WH-OVER-01')
            ->and($e->getMessage())->toContain('5.0000')
            ->and($e->getMessage())->toContain('6.0000');
    }

    expect($thrown)->toBeTrue();
    expect(StockMovement::count())->toBe($movementsBefore);

    $balanceAfter = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->firstOrFail();
    expect($balanceAfter->quantity)->toBe($balanceBefore->quantity)
        ->and($balanceAfter->avg_cost)->toBe($balanceBefore->avg_cost);
});

test('decrease when balance row does not exist throws InsufficientStockException and creates no balance row', function (): void {
    $userId = TestDataFactory::createUser();
    $this->actingAs(User::findOrFail($userId));

    $productId = TestDataFactory::createProduct(['sku' => 'SKU-NO-BAL', 'track_stock' => true]);
    $warehouseId = TestDataFactory::createWarehouse(['code' => 'WH-NO-BAL']);

    $service = new StockService;

    expect(StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->exists())->toBeFalse();

    $thrown = false;
    try {
        $service->decrease($productId, $warehouseId, '1.0000', StockMovementType::DELIVERY, 'delivery_order', 1);
    } catch (InsufficientStockException $e) {
        $thrown = true;
        expect($e->getField())->toBe('quantity')
            ->and($e->getMessage())->toContain('SKU-NO-BAL')
            ->and($e->getMessage())->toContain('WH-NO-BAL');
    }

    expect($thrown)->toBeTrue();
    expect(StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->exists())->toBeFalse();
});

test('type mismatch, invalid quantities, non-tracked products, and soft-deleted entities are rejected', function (): void {
    $userId = TestDataFactory::createUser();
    $this->actingAs(User::findOrFail($userId));

    $productId = TestDataFactory::createProduct(['track_stock' => true]);
    $warehouseId = TestDataFactory::createWarehouse();
    $service = new StockService;

    // Positive control: valid increase and decrease succeed
    $posIncrease = $service->increase($productId, $warehouseId, '10.0000', '100.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);
    expect($posIncrease->id)->toBeGreaterThan(0);
    $posDecrease = $service->decrease($productId, $warehouseId, '5.0000', StockMovementType::DELIVERY, 'delivery_order', 2);
    expect($posDecrease->id)->toBeGreaterThan(0);

    // Negative: type not matching direction
    expect(fn () => $service->increase($productId, $warehouseId, '1', '100', StockMovementType::DELIVERY, 'test', 1))
        ->toThrow(InvalidArgumentException::class, "Invalid movement type 'delivery' for stock increase.");
    expect(fn () => $service->decrease($productId, $warehouseId, '1', StockMovementType::RECEIPT, 'test', 1))
        ->toThrow(InvalidArgumentException::class, "Invalid movement type 'receipt' for stock decrease.");

    // Negative: quantity 0, negative, > 4 decimals
    expect(fn () => $service->increase($productId, $warehouseId, '0', '100', StockMovementType::RECEIPT, 'test', 1))
        ->toThrow(BusinessRuleException::class);
    expect(fn () => $service->increase($productId, $warehouseId, '-1.0000', '100', StockMovementType::RECEIPT, 'test', 1))
        ->toThrow(BusinessRuleException::class);
    expect(fn () => $service->increase($productId, $warehouseId, '1.12345', '100', StockMovementType::RECEIPT, 'test', 1))
        ->toThrow(BusinessRuleException::class);

    // Negative: unitCost negative, > 4 decimals
    expect(fn () => $service->increase($productId, $warehouseId, '1', '-10.0000', StockMovementType::RECEIPT, 'test', 1))
        ->toThrow(BusinessRuleException::class);
    expect(fn () => $service->increase($productId, $warehouseId, '1', '10.12345', StockMovementType::RECEIPT, 'test', 1))
        ->toThrow(BusinessRuleException::class);

    // Negative: track_stock = false
    $nonTrackedId = TestDataFactory::createProduct(['track_stock' => false]);
    expect(fn () => $service->increase($nonTrackedId, $warehouseId, '1', '100', StockMovementType::RECEIPT, 'test', 1))
        ->toThrow(BusinessRuleException::class, 'tidak dikonfigurasi untuk pelacakan stok');

    // Negative: soft-deleted product
    $softDeletedProductId = TestDataFactory::createProduct(['track_stock' => true]);
    Product::find($softDeletedProductId)->delete();
    expect(fn () => $service->increase($softDeletedProductId, $warehouseId, '1', '100', StockMovementType::RECEIPT, 'test', 1))
        ->toThrow(BusinessRuleException::class, "Produk dengan ID {$softDeletedProductId} tidak ditemukan.");

    // Negative: soft-deleted warehouse
    $softDeletedWarehouseId = TestDataFactory::createWarehouse();
    Warehouse::find($softDeletedWarehouseId)->delete();
    expect(fn () => $service->increase($productId, $softDeletedWarehouseId, '1', '100', StockMovementType::RECEIPT, 'test', 1))
        ->toThrow(BusinessRuleException::class, "Gudang dengan ID {$softDeletedWarehouseId} tidak ditemukan.");

    // Negative: non-existent product / warehouse
    expect(fn () => $service->increase(99999999, $warehouseId, '1', '100', StockMovementType::RECEIPT, 'test', 1))
        ->toThrow(BusinessRuleException::class, 'Produk dengan ID 99999999 tidak ditemukan.');
    expect(fn () => $service->increase($productId, 99999999, '1', '100', StockMovementType::RECEIPT, 'test', 1))
        ->toThrow(BusinessRuleException::class, 'Gudang dengan ID 99999999 tidak ditemukan.');
});

test('database constraint chk_stock_balances_quantity strictly prevents negative balance', function (): void {
    $productId = TestDataFactory::createProduct();
    $warehouseId = TestDataFactory::createWarehouse();

    $id = DB::table('stock_balances')->insertGetId([
        'product_id' => $productId,
        'warehouse_id' => $warehouseId,
        'quantity' => '5.0000',
        'avg_cost' => '1000.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Positive control: valid update succeeds
    DB::table('stock_balances')->where('id', $id)->update(['quantity' => '10.0000']);
    expect(DB::table('stock_balances')->where('id', $id)->value('quantity'))->toBe('10.0000');

    // Negative: negative quantity fails MariaDB CHECK constraint
    $checkFailed = false;
    try {
        DB::table('stock_balances')->where('id', $id)->update(['quantity' => '-1.0000']);
    } catch (QueryException $e) {
        $checkFailed = true;
        expect($e->getMessage())->toContain('chk_stock_balances_quantity');
    }
    expect($checkFailed)->toBeTrue('Setting negative quantity on stock_balances must fail CHECK constraint');
});
