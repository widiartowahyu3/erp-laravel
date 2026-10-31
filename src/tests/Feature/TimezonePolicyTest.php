<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Inventory\StockService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\Support\TestDataFactory;

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

test('application timezone and database session timezone are aligned to Asia/Jakarta (+07:00)', function (): void {
    expect(config('app.timezone'))->toBe('Asia/Jakarta')
        ->and(date_default_timezone_get())->toBe('Asia/Jakarta');

    $dbTz = DB::selectOne('SELECT @@session.time_zone AS tz');
    expect($dbTz->tz)->toBe('+07:00');
});

test('carbon today and before_or_equal validator resolve correctly during WIB midnight-to-morning interval', function (): void {
    // 2026-10-30 18:00:00 UTC = 2026-10-31 01:00:00 WIB
    Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-30 18:00:00', 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::createFromFormat('Y-m-d H:i:s', '2026-10-30 18:00:00', 'UTC'));

    expect(today()->toDateString())->toBe('2026-10-31');

    $validator = Validator::make(
        ['date' => '2026-10-31'],
        ['date' => 'required|date|before_or_equal:today']
    );
    expect($validator->passes())->toBeTrue();

    // Integration check: POST /inventory/stock-adjustments accepts today (2026-10-31)
    $user = userWithPermissions([
        'stock_adjustments.create',
        'stock_adjustments.view',
        'goods_receipts.create',
        'goods_receipts.view',
    ]);
    $this->actingAs($user);

    $wh = TestDataFactory::createWarehouse();
    $p = TestDataFactory::createProduct(['track_stock' => true]);

    $saResponse = $this->post('/inventory/stock-adjustments', [
        'warehouse_id' => $wh,
        'adjustment_date' => '2026-10-31',
        'reason' => 'Test timezone alignment',
        'items' => [
            [
                'product_id' => $p,
                'counted_quantity' => '10.0000',
            ],
        ],
    ]);
    expect($saResponse->status())->toBe(302);
    $saResponse->assertSessionHasNoErrors();

    // Integration check: POST /purchasing/goods-receipts accepts today (2026-10-31)
    $supplierId = TestDataFactory::createSupplier();
    $po = PurchaseOrder::create([
        'number' => 'PO-TZ-001',
        'supplier_id' => $supplierId,
        'warehouse_id' => $wh,
        'order_date' => '2026-10-31',
        'status' => PurchaseOrderStatus::APPROVED,
        'subtotal' => '10000.0000',
        'discount_amount' => '0.0000',
        'tax_amount' => '0.0000',
        'total' => '10000.0000',
    ]);

    $poItem = PurchaseOrderItem::create([
        'purchase_order_id' => $po->id,
        'line_no' => 1,
        'product_id' => $p,
        'quantity' => '5.0000',
        'unit_price' => '2000.0000',
        'discount_amount' => '0.0000',
        'tax_amount' => '0.0000',
        'line_total' => '10000.0000',
    ]);

    $grResponse = $this->post('/purchasing/goods-receipts', [
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $wh,
        'receipt_date' => '2026-10-31',
        'items' => [
            [
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $p,
                'quantity' => '5.0000',
            ],
        ],
    ]);
    expect($grResponse->status())->toBe(302);
    $grResponse->assertSessionHasNoErrors();
});

test('stock movement movement_date and db created_at differ by at most 5 seconds', function (): void {
    $wh = TestDataFactory::createWarehouse();
    $p = TestDataFactory::createProduct(['track_stock' => true]);

    $stockService = app(StockService::class);
    $stockService->increase(
        productId: $p,
        warehouseId: $wh,
        quantity: '5.0000',
        unitCost: '1500.0000',
        type: StockMovementType::RECEIPT,
        sourceType: StockService::SOURCE_GOODS_RECEIPT,
        sourceId: 999
    );

    $movement = DB::table('stock_movements')
        ->where('product_id', $p)
        ->where('warehouse_id', $wh)
        ->first();

    expect($movement)->not->toBeNull();

    $movementDate = Carbon::parse($movement->movement_date, config('app.timezone'));
    $createdAt = Carbon::parse($movement->created_at, config('app.timezone'));

    $diffSeconds = abs($movementDate->diffInSeconds($createdAt));
    expect($diffSeconds)->toBeLessThanOrEqual(5);
});

test('eloquent timestamp unix timestamp matches system time and json serialization represents same instant', function (): void {
    $now = time();
    $catId = TestDataFactory::createCategory();
    $unitId = TestDataFactory::createUnit();

    $product = Product::create([
        'sku' => 'SKU-TZ-'.bin2hex(random_bytes(4)),
        'name' => 'Timezone Test Product',
        'category_id' => $catId,
        'base_unit_id' => $unitId,
        'track_stock' => true,
        'selling_price' => '10000.0000',
        'min_stock' => '0.0000',
        'is_active' => true,
    ]);

    $dbTimestamp = (int) DB::table('products')
        ->where('id', $product->id)
        ->selectRaw('UNIX_TIMESTAMP(created_at) AS unix_ts')
        ->value('unix_ts');

    expect(abs($dbTimestamp - $now))->toBeLessThanOrEqual(5);

    $json = $product->toJson();
    $decoded = json_decode($json, true);
    expect($decoded)->toHaveKey('created_at');

    $jsonCreatedAt = Carbon::parse($decoded['created_at']);
    expect(abs($jsonCreatedAt->getTimestamp() - $now))->toBeLessThanOrEqual(5);
});
