<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StockAdjustmentStatus;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\TestDataFactory;

test('stock models have correct table mappings, relations, and casts', function (): void {
    $userId = TestDataFactory::createUser();
    $user = User::findOrFail($userId);
    $this->actingAs($user);

    $productId = TestDataFactory::createProduct();
    $product = Product::findOrFail($productId);

    $warehouseId = TestDataFactory::createWarehouse();
    $warehouse = Warehouse::findOrFail($warehouseId);

    // 1. StockBalance
    $balance = StockBalance::create([
        'product_id' => $productId,
        'warehouse_id' => $warehouseId,
        'quantity' => '10.5000',
        'avg_cost' => '1250.7500',
    ]);

    expect($balance->getTable())->toBe('stock_balances')
        ->and($balance->product->id)->toBe($productId)
        ->and($balance->warehouse->id)->toBe($warehouseId)
        ->and($balance->quantity)->toBe('10.5000')
        ->and($balance->avg_cost)->toBe('1250.7500');

    // 2. StockAdjustment & StockAdjustmentItem
    $adjustment = StockAdjustment::create([
        'number' => 'SA/202610/00001',
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Stock opname akhir bulan',
        'status' => StockAdjustmentStatus::DRAFT,
        'notes' => 'Catatan opname',
    ]);

    $item = StockAdjustmentItem::create([
        'stock_adjustment_id' => $adjustment->id,
        'line_no' => 1,
        'product_id' => $productId,
        'system_quantity' => '10.0000',
        'counted_quantity' => '12.0000',
        'difference_quantity' => '2.0000',
        'unit_cost' => '1250.0000',
    ]);

    expect($adjustment->getTable())->toBe('stock_adjustments')
        ->and($adjustment->status)->toBe(StockAdjustmentStatus::DRAFT)
        ->and($adjustment->adjustment_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($adjustment->warehouse->id)->toBe($warehouseId)
        ->and($adjustment->created_by)->toBe($userId)
        ->and($adjustment->updated_by)->toBe($userId)
        ->and($adjustment->items)->toHaveCount(1)
        ->and($adjustment->items->first()->id)->toBe($item->id)
        ->and($item->getTable())->toBe('stock_adjustment_items')
        ->and($item->adjustment->id)->toBe($adjustment->id)
        ->and($item->product->id)->toBe($productId)
        ->and($item->system_quantity)->toBe('10.0000')
        ->and($item->counted_quantity)->toBe('12.0000')
        ->and($item->difference_quantity)->toBe('2.0000')
        ->and($item->unit_cost)->toBe('1250.0000');

    // Test poster and voider relations
    $adjustment->update([
        'posted_by' => $userId,
        'posted_at' => now(),
        'voided_by' => $userId,
        'voided_at' => now(),
        'void_reason' => 'Alasan pembatalan',
    ]);
    expect($adjustment->poster?->id)->toBe($userId)
        ->and($adjustment->voider?->id)->toBe($userId);
});

test('StockMovement cannot be updated or deleted via Eloquent and throws LogicException', function (): void {
    $userId = TestDataFactory::createUser();
    $productId = TestDataFactory::createProduct();
    $warehouseId = TestDataFactory::createWarehouse();

    // Control positive: inserting a valid movement via Eloquent succeeds
    $movement = StockMovement::create([
        'movement_date' => now(),
        'product_id' => $productId,
        'warehouse_id' => $warehouseId,
        'movement_type' => StockMovementType::RECEIPT,
        'quantity' => '5.0000',
        'unit_cost' => '1000.0000',
        'balance_after' => '5.0000',
        'avg_cost_after' => '1000.0000',
        'source_type' => 'goods_receipt',
        'source_id' => 1,
        'created_by' => $userId,
    ]);

    expect($movement->id)->toBeGreaterThan(0)
        ->and($movement->movement_type)->toBe(StockMovementType::RECEIPT)
        ->and($movement->product->id)->toBe($productId)
        ->and($movement->warehouse->id)->toBe($warehouseId)
        ->and($movement->creator?->id)->toBe($userId);

    // Negative test: Eloquent update fails
    expect(fn () => $movement->update(['source_type' => 'tampered']))
        ->toThrow(LogicException::class, 'stock_movements is append-only');

    // Negative test: Eloquent delete fails
    expect(fn () => $movement->delete())
        ->toThrow(LogicException::class, 'stock_movements is append-only');
});

test('StockMovement cannot be updated or deleted via direct DB query and is rejected by database trigger', function (): void {
    $userId = TestDataFactory::createUser();
    $productId = TestDataFactory::createProduct();
    $warehouseId = TestDataFactory::createWarehouse();

    // Control positive: direct DB insert succeeds
    $movementId = DB::table('stock_movements')->insertGetId([
        'movement_date' => now(),
        'product_id' => $productId,
        'warehouse_id' => $warehouseId,
        'movement_type' => 'receipt',
        'quantity' => '10.0000',
        'unit_cost' => '1500.0000',
        'balance_after' => '10.0000',
        'avg_cost_after' => '1500.0000',
        'source_type' => 'goods_receipt',
        'source_id' => 2,
        'created_by' => $userId,
        'created_at' => now(),
    ]);

    expect($movementId)->toBeGreaterThan(0);

    // Negative test: DB update rejected by trigger
    $updateFailed = false;
    try {
        DB::table('stock_movements')
            ->where('id', $movementId)
            ->update(['source_type' => 'tampered']);
    } catch (QueryException $e) {
        $updateFailed = true;
        expect($e->getMessage())->toContain('stock_movements is append-only');
    }
    expect($updateFailed)->toBeTrue('Direct DB update on stock_movements should be rejected by trigger');

    // Negative test: DB delete rejected by trigger
    $deleteFailed = false;
    try {
        DB::table('stock_movements')
            ->where('id', $movementId)
            ->delete();
    } catch (QueryException $e) {
        $deleteFailed = true;
        expect($e->getMessage())->toContain('stock_movements is append-only');
    }
    expect($deleteFailed)->toBeTrue('Direct DB delete on stock_movements should be rejected by trigger');
});
