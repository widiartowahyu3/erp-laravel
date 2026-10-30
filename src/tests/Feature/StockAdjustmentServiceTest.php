<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StockAdjustmentStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDataFactory;

test('create generates sequential numbers, draft status, line numbers, system quantity, and audit logs', function (): void {
    $userId = TestDataFactory::createUser();
    $this->actingAs(User::findOrFail($userId));

    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $stockService = new StockService;
    // Prepare initial balance: 5.0000
    $stockService->increase($productId, $warehouseId, '5.0000', '1000.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);

    $service = new StockAdjustmentService($stockService);
    $adjDate = '2026-10-30';
    $period = Carbon::parse($adjDate)->format('Ym');

    $adj1 = $service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => $adjDate,
        'reason' => 'Opname 1',
        'items' => [
            ['product_id' => $productId, 'counted_quantity' => '7.0000', 'unit_cost' => '1000.0000'],
        ],
    ]);

    expect($adj1->number)->toBe("SA/{$period}/00001")
        ->and($adj1->status)->toBe(StockAdjustmentStatus::DRAFT)
        ->and($adj1->items)->toHaveCount(1)
        ->and($adj1->items->first()->line_no)->toBe(1)
        ->and($adj1->items->first()->system_quantity)->toBe('5.0000')
        ->and($adj1->items->first()->difference_quantity)->toBe('2.0000');

    $adj2 = $service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => $adjDate,
        'reason' => 'Opname 2',
        'items' => [
            ['product_id' => $productId, 'counted_quantity' => '5.0000'],
        ],
    ]);

    expect($adj2->number)->toBe("SA/{$period}/00002");

    // Check audit_logs for stock_adjustment.created
    $auditLog = DB::table('audit_logs')
        ->where('auditable_type', StockAdjustment::class)
        ->where('auditable_id', $adj1->id)
        ->where('event', 'stock_adjustment.created')
        ->first();

    expect($auditLog)->not->toBeNull();
});

test('update replaces draft items and header; non-draft update throws DocumentNotEditableException', function (): void {
    $userId = TestDataFactory::createUser();
    $this->actingAs(User::findOrFail($userId));

    $warehouseId = TestDataFactory::createWarehouse();
    $productIdA = TestDataFactory::createProduct(['track_stock' => true]);
    $productIdB = TestDataFactory::createProduct(['track_stock' => true]);

    $stockService = new StockService;
    $service = new StockAdjustmentService($stockService);

    $adj = $service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Alasan Awal',
        'items' => [
            ['product_id' => $productIdA, 'counted_quantity' => '10.0000'],
        ],
    ]);

    // Update draft
    $updated = $service->update($adj, [
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Alasan Diperbarui',
        'notes' => 'Catatan revisi',
        'items' => [
            ['product_id' => $productIdB, 'counted_quantity' => '15.0000', 'unit_cost' => '1200.0000'],
        ],
    ]);

    expect($updated->reason)->toBe('Alasan Diperbarui')
        ->and($updated->notes)->toBe('Catatan revisi')
        ->and($updated->items)->toHaveCount(1)
        ->and($updated->items->first()->product_id)->toBe($productIdB)
        ->and($updated->items->first()->counted_quantity)->toBe('15.0000');

    // Post it, then update must fail
    $service->post($updated);

    expect(fn () => $service->update($updated, [
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Coba ubah posted',
        'items' => [['product_id' => $productIdB, 'counted_quantity' => '20.0000']],
    ]))->toThrow(DocumentNotEditableException::class);
});

test('post handles positive difference with unit cost and creates audit log', function (): void {
    $userId = TestDataFactory::createUser();
    $this->actingAs(User::findOrFail($userId));

    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $stockService = new StockService;
    // Saldo awal 10 @ 1100
    $stockService->increase($productId, $warehouseId, '10.0000', '1100.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);

    $service = new StockAdjustmentService($stockService);
    $adj = $service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Fisik lebih',
        'items' => [
            ['product_id' => $productId, 'counted_quantity' => '12.0000', 'unit_cost' => '1200.0000'],
        ],
    ]);

    $posted = $service->post($adj);

    expect($posted->status)->toBe(StockAdjustmentStatus::POSTED)
        ->and($posted->posted_by)->toBe($userId)
        ->and($posted->posted_at)->not->toBeNull();

    $item = $posted->items->first();
    expect($item->system_quantity)->toBe('10.0000')
        ->and($item->difference_quantity)->toBe('2.0000');

    $balance = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->firstOrFail();
    expect($balance->quantity)->toBe('12.0000')
        ->and($balance->avg_cost)->toBe('1116.6667');

    $movement = StockMovement::where('source_type', StockService::SOURCE_STOCK_ADJUSTMENT)
        ->where('source_id', $adj->id)
        ->firstOrFail();

    expect($movement->quantity)->toBe('2.0000')
        ->and($movement->unit_cost)->toBe('1200.0000')
        ->and($movement->movement_type)->toBe(StockMovementType::ADJUSTMENT_IN)
        ->and($movement->balance_after)->toBe('12.0000')
        ->and($movement->avg_cost_after)->toBe('1116.6667');

    // Audit log stock_adjustment.updated
    $auditLog = DB::table('audit_logs')
        ->where('auditable_type', StockAdjustment::class)
        ->where('auditable_id', $adj->id)
        ->where('event', 'stock_adjustment.updated')
        ->first();

    expect($auditLog)->not->toBeNull();
});

test('post handles negative difference using current avg cost as unit cost', function (): void {
    $userId = TestDataFactory::createUser();
    $this->actingAs(User::findOrFail($userId));

    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $stockService = new StockService;
    // Saldo 12 @ 1116.6667 (terima 10 @ 1100 lalu 2 @ 1200)
    $stockService->increase($productId, $warehouseId, '10.0000', '1100.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);
    $stockService->increase($productId, $warehouseId, '2.0000', '1200.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 2);

    $service = new StockAdjustmentService($stockService);
    $adj = $service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Fisik kurang',
        'items' => [
            ['product_id' => $productId, 'counted_quantity' => '9.0000'],
        ],
    ]);

    $posted = $service->post($adj);

    $item = $posted->items->first();
    expect($item->system_quantity)->toBe('12.0000')
        ->and($item->difference_quantity)->toBe('-3.0000')
        ->and($item->unit_cost)->toBe('1116.6667');

    $balance = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->firstOrFail();
    expect($balance->quantity)->toBe('9.0000')
        ->and($balance->avg_cost)->toBe('1116.6667');

    $movement = StockMovement::where('source_type', StockService::SOURCE_STOCK_ADJUSTMENT)
        ->where('source_id', $adj->id)
        ->firstOrFail();

    expect($movement->quantity)->toBe('-3.0000')
        ->and($movement->unit_cost)->toBe('1116.6667')
        ->and($movement->movement_type)->toBe(StockMovementType::ADJUSTMENT_OUT)
        ->and($movement->balance_after)->toBe('9.0000')
        ->and($movement->avg_cost_after)->toBe('1116.6667');
});

test('post with zero difference creates no movement; positive difference with zero unit cost rolls back', function (): void {
    $userId = TestDataFactory::createUser();
    $this->actingAs(User::findOrFail($userId));

    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $stockService = new StockService;
    $stockService->increase($productId, $warehouseId, '10.0000', '1000.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);

    $service = new StockAdjustmentService($stockService);

    // 1. Zero difference
    $adjZero = $service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Sesuai fisik',
        'items' => [
            ['product_id' => $productId, 'counted_quantity' => '10.0000'],
        ],
    ]);

    $movementsBefore = StockMovement::count();
    $service->post($adjZero);
    expect(StockMovement::count())->toBe($movementsBefore)
        ->and($adjZero->fresh()->status)->toBe(StockAdjustmentStatus::POSTED);

    // 2. Positive difference with zero unit cost
    $adjPositiveZeroCost = $service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Lebih tapi tanpa biaya',
        'items' => [
            ['product_id' => $productId, 'counted_quantity' => '15.0000', 'unit_cost' => '0.0000'],
        ],
    ]);

    $movementsBeforeFail = StockMovement::count();
    expect(fn () => $service->post($adjPositiveZeroCost))
        ->toThrow(BusinessRuleException::class, 'Item dengan selisih lebih wajib memiliki biaya satuan lebih dari 0.');

    expect(StockMovement::count())->toBe($movementsBeforeFail)
        ->and($adjPositiveZeroCost->fresh()->status)->toBe(StockAdjustmentStatus::DRAFT);
});

test('system_quantity is dynamically re-evaluated from locked balance at posting time', function (): void {
    $userId = TestDataFactory::createUser();
    $this->actingAs(User::findOrFail($userId));

    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $stockService = new StockService;
    $stockService->increase($productId, $warehouseId, '10.0000', '1000.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);

    $service = new StockAdjustmentService($stockService);

    // Create draft: system_quantity = 10.0000, counted = 12.0000
    $adj = $service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Draft test recompute',
        'items' => [
            ['product_id' => $productId, 'counted_quantity' => '12.0000', 'unit_cost' => '1000.0000'],
        ],
    ]);

    // Change stock AFTER draft creation: receive 1 more -> balance becomes 11.0000
    $stockService->increase($productId, $warehouseId, '1.0000', '1000.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 2);

    // Now post the draft: system_quantity must re-evaluate to 11.0000 and difference to 1.0000
    $posted = $service->post($adj);
    $item = $posted->items->first();

    expect($item->system_quantity)->toBe('11.0000')
        ->and($item->difference_quantity)->toBe('1.0000');

    $balance = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->firstOrFail();
    expect($balance->quantity)->toBe('12.0000');
});

test('items ordered in reverse are processed with ascending product_id lock order', function (): void {
    $userId = TestDataFactory::createUser();
    $this->actingAs(User::findOrFail($userId));

    $warehouseId = TestDataFactory::createWarehouse();
    $p1 = TestDataFactory::createProduct(['track_stock' => true]);
    $p2 = TestDataFactory::createProduct(['track_stock' => true]);
    $lowId = min($p1, $p2);
    $highId = max($p1, $p2);

    $stockService = new StockService;
    $service = new StockAdjustmentService($stockService);

    // Create draft with higher product ID first
    $adj = $service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Reverse order test',
        'items' => [
            ['product_id' => $highId, 'counted_quantity' => '5.0000', 'unit_cost' => '100.0000'],
            ['product_id' => $lowId, 'counted_quantity' => '3.0000', 'unit_cost' => '100.0000'],
        ],
    ]);

    $service->post($adj);

    $movements = StockMovement::where('source_type', StockService::SOURCE_STOCK_ADJUSTMENT)
        ->where('source_id', $adj->id)
        ->orderBy('id', 'asc')
        ->get();

    expect($movements)->toHaveCount(2)
        ->and($movements[0]->product_id)->toBe($lowId)
        ->and($movements[1]->product_id)->toBe($highId);
});

test('post twice throws InvalidStateTransitionException; void handles draft and posted semantics', function (): void {
    $userId = TestDataFactory::createUser();
    $this->actingAs(User::findOrFail($userId));

    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $stockService = new StockService;
    $stockService->increase($productId, $warehouseId, '10.0000', '1000.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);

    $service = new StockAdjustmentService($stockService);

    // 1. Post twice
    $adj = $service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Test double post',
        'items' => [
            ['product_id' => $productId, 'counted_quantity' => '12.0000', 'unit_cost' => '1000.0000'],
        ],
    ]);
    $service->post($adj);
    expect(fn () => $service->post($adj))->toThrow(InvalidStateTransitionException::class);

    // 2. Void draft: no movements
    $draftAdj = $service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Draft to void',
        'items' => [
            ['product_id' => $productId, 'counted_quantity' => '15.0000', 'unit_cost' => '1000.0000'],
        ],
    ]);
    $movementsBeforeDraftVoid = StockMovement::count();
    $voidedDraft = $service->void($draftAdj, 'Salah input');
    expect($voidedDraft->status)->toBe(StockAdjustmentStatus::VOID)
        ->and(StockMovement::count())->toBe($movementsBeforeDraftVoid);

    // 3. Void reason validation: empty or > 255
    expect(fn () => $service->void($adj, ''))
        ->toThrow(BusinessRuleException::class, 'Alasan pembatalan wajib diisi');
    expect(fn () => $service->void($adj, str_repeat('a', 256)))
        ->toThrow(BusinessRuleException::class, 'Alasan pembatalan wajib diisi');

    // 4. Void when stock cannot be reversed (stock consumed)
    // $adj added +2 (balance is 12). Consume 11 -> balance becomes 1.
    $stockService->decrease($productId, $warehouseId, '11.0000', StockMovementType::DELIVERY, 'delivery_order', 1);

    $movementsBeforeFail = StockMovement::count();
    expect(fn () => $service->void($adj, 'Coba batalkan tapi stok kurang'))
        ->toThrow(InsufficientStockException::class);

    expect($adj->fresh()->status)->toBe(StockAdjustmentStatus::POSTED)
        ->and(StockMovement::count())->toBe($movementsBeforeFail);

    // Replenish stock to allow void: increase 5 -> balance becomes 6
    $stockService->increase($productId, $warehouseId, '5.0000', '1000.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 2);

    $voidedPosted = $service->void($adj, 'Batalkan penyesuaian yang sah');
    expect($voidedPosted->status)->toBe(StockAdjustmentStatus::VOID)
        ->and($voidedPosted->void_reason)->toBe('Batalkan penyesuaian yang sah');

    $reversalMovement = StockMovement::where('source_type', StockService::SOURCE_STOCK_ADJUSTMENT_VOID)
        ->where('source_id', $adj->id)
        ->first();
    expect($reversalMovement)->not->toBeNull()
        ->and($reversalMovement->quantity)->toBe('-2.0000');
});

test('ledger consistency: sum of movements equals balance quantity and last balance_after equals balance', function (): void {
    $userId = TestDataFactory::createUser();
    $this->actingAs(User::findOrFail($userId));

    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct(['track_stock' => true]);

    $stockService = new StockService;
    $service = new StockAdjustmentService($stockService);

    $stockService->increase($productId, $warehouseId, '20.0000', '1000.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);
    $stockService->decrease($productId, $warehouseId, '5.0000', StockMovementType::DELIVERY, 'delivery_order', 1);

    $adj1 = $service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Opname naik',
        'items' => [['product_id' => $productId, 'counted_quantity' => '18.0000', 'unit_cost' => '1000.0000']],
    ]);
    $service->post($adj1);

    $adj2 = $service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-30',
        'reason' => 'Opname turun',
        'items' => [['product_id' => $productId, 'counted_quantity' => '14.0000']],
    ]);
    $service->post($adj2);

    $sumQty = (string) DB::table('stock_movements')
        ->where('product_id', $productId)
        ->where('warehouse_id', $warehouseId)
        ->sum('quantity');

    $balance = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->firstOrFail();
    $lastMovement = StockMovement::where('product_id', $productId)
        ->where('warehouse_id', $warehouseId)
        ->orderBy('id', 'desc')
        ->firstOrFail();

    expect(bccomp($sumQty, $balance->quantity, 4))->toBe(0)
        ->and($lastMovement->balance_after)->toBe($balance->quantity);
});
