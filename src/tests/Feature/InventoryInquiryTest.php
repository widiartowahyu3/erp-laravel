<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockBalance;
use App\Services\Inventory\StockService;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    config(['inertia.testing.ensure_pages_exist' => false]);
});

test('inventory inquiry routes enforce authentication and permissions', function (): void {
    // 1. Guest redirected to login
    $this->get('/inventory/stock-balances')->assertRedirect('/login');
    $this->get('/inventory/stock-movements')->assertRedirect('/login');
    $this->get('/inventory/stock-card')->assertRedirect('/login');

    // 2. User without permissions gets 403
    $userWithoutPerm = userWithPermissions([]);
    $this->flushSession();
    $this->actingAs($userWithoutPerm)->get('/inventory/stock-balances')->assertStatus(403);
    $this->flushSession();
    $this->actingAs($userWithoutPerm)->get('/inventory/stock-movements')->assertStatus(403);
    $this->flushSession();
    $this->actingAs($userWithoutPerm)->get('/inventory/stock-card')->assertStatus(403);

    // 3. User with stock_balances.view can access stock-balances
    $userBalance = userWithPermissions(['stock_balances.view']);
    $this->flushSession();
    $this->actingAs($userBalance)->get('/inventory/stock-balances')
        ->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page->component('Inventory/StockBalances/Index'));

    // 4. User with stock_movements.view can access stock-movements and stock-card
    $userMovement = userWithPermissions(['stock_movements.view']);
    $this->flushSession();
    $this->actingAs($userMovement)->get('/inventory/stock-movements')
        ->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page->component('Inventory/StockMovements/Index'));

    $this->flushSession();
    $this->actingAs($userMovement)->get('/inventory/stock-card')
        ->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page->component('Inventory/StockCard/Index'));
});

test('stock balances index returns formatted props, filters, stock value, and below_minimum boundary checks', function (): void {
    $user = userWithPermissions(['stock_balances.view']);
    $this->actingAs($user);

    $wh1 = TestDataFactory::createWarehouse();
    $wh2 = TestDataFactory::createWarehouse();

    // Product 1: min_stock = 10.0000, qty = 9.0000 -> below_minimum = true
    $p1 = TestDataFactory::createProduct(['sku' => 'PROD-BELOW', 'name' => 'Produk Dibawah Min', 'min_stock' => '10.0000', 'track_stock' => true]);
    StockBalance::create(['product_id' => $p1, 'warehouse_id' => $wh1, 'quantity' => '9.0000', 'avg_cost' => '1000.0000']);

    // Product 2: min_stock = 10.0000, qty = 10.0000 -> below_minimum = false
    $p2 = TestDataFactory::createProduct(['sku' => 'PROD-EQUAL', 'name' => 'Produk Sama Min', 'min_stock' => '10.0000', 'track_stock' => true]);
    StockBalance::create(['product_id' => $p2, 'warehouse_id' => $wh1, 'quantity' => '10.0000', 'avg_cost' => '2500.5000']);

    // Product 3: min_stock = 10.0000, qty = 11.0000 -> below_minimum = false
    $p3 = TestDataFactory::createProduct(['sku' => 'PROD-ABOVE', 'name' => 'Produk Diatas Min', 'min_stock' => '10.0000', 'track_stock' => true]);
    StockBalance::create(['product_id' => $p3, 'warehouse_id' => $wh2, 'quantity' => '11.0000', 'avg_cost' => '3000.0000']);

    // Product 4 with literal wildcards % and _ in name and sku
    $p4 = TestDataFactory::createProduct(['sku' => '100%_NATURAL', 'name' => 'Super 50%_Off Item', 'min_stock' => '5.0000', 'track_stock' => true]);
    StockBalance::create(['product_id' => $p4, 'warehouse_id' => $wh1, 'quantity' => '20.0000', 'avg_cost' => '500.0000']);

    // 1. Verify below_minimum boundary and stock_value
    $res = $this->get('/inventory/stock-balances');
    $res->assertStatus(200);

    $res->assertInertia(function (Assert $page) use ($p1, $p2, $p3): void {
        $page->component('Inventory/StockBalances/Index')
            ->has('items.data')
            ->where('items.data', function ($items) use ($p1, $p2, $p3): bool {
                $item1 = collect($items)->firstWhere('product_id', $p1);
                $item2 = collect($items)->firstWhere('product_id', $p2);
                $item3 = collect($items)->firstWhere('product_id', $p3);

                return $item1['below_minimum'] === true
                    && $item1['stock_value'] === '9000.00' // 9.0000 * 1000.0000
                    && $item2['below_minimum'] === false
                    && $item2['stock_value'] === '25005.00' // 10.0000 * 2500.5000
                    && $item3['below_minimum'] === false;
            });
    });

    // 2. Filter by warehouse_id
    $resWh2 = $this->get("/inventory/stock-balances?filter[warehouse_id]={$wh2}");
    $resWh2->assertInertia(function (Assert $page) use ($p3): void {
        $page->has('items.data', 1)
            ->where('items.data.0.product_id', $p3);
    });

    // 3. Search literal with % wildcard
    $resSearchPercent = $this->get('/inventory/stock-balances?filter[q]=100%');
    $resSearchPercent->assertInertia(function (Assert $page) use ($p4): void {
        $page->has('items.data', 1)
            ->where('items.data.0.product_id', $p4);
    });
});

test('stock movements index returns filtered list and maps source_url', function (): void {
    $user = userWithPermissions(['stock_movements.view']);
    $this->actingAs($user);

    $wh = TestDataFactory::createWarehouse();
    $p = TestDataFactory::createProduct(['track_stock' => true]);

    $stockService = new StockService;
    $m1 = $stockService->increase($p, $wh, '10.0000', '1000.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 77);
    $m2 = $stockService->decrease($p, $wh, '3.0000', StockMovementType::DELIVERY, 'delivery_order', 88);
    $m3 = $stockService->decrease($p, $wh, '2.0000', StockMovementType::ADJUSTMENT_OUT, StockService::SOURCE_GOODS_RECEIPT_VOID, 77);

    $res = $this->get('/inventory/stock-movements');
    $res->assertStatus(200)
        ->assertInertia(function (Assert $page) use ($m1, $m2, $m3): void {
            $page->component('Inventory/StockMovements/Index')
                ->has('items.data')
                ->has('movementTypes')
                ->where('items.data', function ($items) use ($m1, $m2, $m3): bool {
                    $row1 = collect($items)->firstWhere('id', $m1->id);
                    $row2 = collect($items)->firstWhere('id', $m2->id);
                    $row3 = collect($items)->firstWhere('id', $m3->id);

                    return $row1['quantity'] === '10.0000'
                        && $row1['movement_type'] === 'receipt'
                        && $row1['source_label'] === 'Penerimaan Barang'
                        && $row2['quantity'] === '-3.0000'
                        && $row2['movement_type'] === 'delivery'
                        && $row2['source_url'] === null
                        && $row3['quantity'] === '-2.0000'
                        && $row3['movement_type'] === 'adjustment_out'
                        && $row3['source_label'] === 'Pembatalan Penerimaan Barang';
                });
        });

    // Filter by movement_type
    $resFiltered = $this->get('/inventory/stock-movements?filter[movement_type]=delivery');
    $resFiltered->assertInertia(function (Assert $page): void {
        $page->where('items.data', fn ($items) => collect($items)->every(fn ($i) => $i['movement_type'] === 'delivery'));
    });
});

test('stock card calculates opening balance with date_from, orders chronologically, formats in and out, and validates dates', function (): void {
    $user = userWithPermissions(['stock_movements.view']);
    $this->actingAs($user);

    $wh = TestDataFactory::createWarehouse();
    $p = TestDataFactory::createProduct(['track_stock' => true]);

    $stockService = new StockService;

    // 1. Without product_id or warehouse_id, returns empty/null rows
    $resEmpty = $this->get('/inventory/stock-card');
    $resEmpty->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page->where('rows', null)->where('opening', null));

    // 2. Prepare movements with past, current, and future dates
    $d1 = Carbon::parse('2026-10-01 10:00:00');
    $d2 = Carbon::parse('2026-10-05 11:00:00');
    $d3 = Carbon::parse('2026-10-10 14:00:00');

    // Movement before date_from
    $stockService->increase($p, $wh, '20.0000', '1000.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1, $d1);
    // Movement inside date_from .. date_to (in)
    $stockService->increase($p, $wh, '5.0000', '1200.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 2, $d2);
    // Movement inside date_from .. date_to (out)
    $stockService->decrease($p, $wh, '7.0000', StockMovementType::DELIVERY, 'delivery_order', 3, $d3);

    // Query stock card from 2026-10-03 to 2026-10-15
    $resCard = $this->get("/inventory/stock-card?product_id={$p}&warehouse_id={$wh}&date_from=2026-10-03&date_to=2026-10-15");
    $resCard->assertStatus(200);

    $resCard->assertInertia(function (Assert $page): void {
        $page->component('Inventory/StockCard/Index')
            // Opening balance is balance from 2026-10-01: qty 20.0000 @ 1000.0000
            ->where('opening.quantity', '20.0000')
            ->where('opening.avg_cost', '1000.0000')
            // Current total balance is 20 + 5 - 7 = 18.0000
            ->where('current.quantity', '18.0000')
            // Rows within range: 2 items (2026-10-05 and 2026-10-10)
            ->has('rows.data', 2)
            ->where('rows.data.0.in', '5.0000')
            ->where('rows.data.0.out', null)
            ->where('rows.data.1.in', null)
            ->where('rows.data.1.out', '7.0000');
    });

    // 3. Invalid date validation error
    $resInvalidDate = $this->get("/inventory/stock-card?product_id={$p}&warehouse_id={$wh}&date_from=invalid-date");
    $resInvalidDate->assertSessionHasErrors('date_from');

    // date_to before date_from
    $resInvertedDates = $this->get("/inventory/stock-card?product_id={$p}&warehouse_id={$wh}&date_from=2026-10-10&date_to=2026-10-05");
    $resInvertedDates->assertSessionHasErrors('date_to');
});
