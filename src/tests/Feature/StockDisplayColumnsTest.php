<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Sales\DeliveryOrderService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AccountingFixtures;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    config(['inertia.testing.ensure_pages_exist' => false]);
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
});

test('stock balances expose the product category and base unit the page displays', function (): void {
    $categoryId = TestDataFactory::createCategory(['name' => 'Mi Instan']);
    $unitId = TestDataFactory::createUnit(['code' => 'DUS', 'name' => 'Dus']);
    $productId = TestDataFactory::createProduct(['category_id' => $categoryId, 'base_unit_id' => $unitId]);
    AccountingFixtures::seedStock($productId, TestDataFactory::createWarehouse(), '10.0000', '100.0000');

    $this->actingAs(userWithPermissions(['stock_balances.view']))
        ->get('/inventory/stock-balances?filter[product_id]='.$productId)
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.product.category.name', 'Mi Instan')
            ->where('items.data.0.product.base_unit.code', 'DUS')
        );
});

test('stock movements expose the real document number of each source, voids included', function (): void {
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();

    $grService = new GoodsReceiptService(new StockService);
    $gr = $grService->post(AccountingFixtures::draftReceipt([['product' => $productId, 'quantity' => '10.0000', 'cost' => '100.0000']], $warehouseId));

    $doService = new DeliveryOrderService(new StockService);
    $do = $doService->post(AccountingFixtures::draftDelivery($productId, $warehouseId, '1.0000', '200.0000'));
    $doService->void($do, 'Pelanggan menolak');

    $saService = new StockAdjustmentService(new StockService);
    $sa = $saService->post($saService->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-27',
        'reason' => 'Stock opname',
        'items' => [['product_id' => $productId, 'counted_quantity' => '5.0000']],
    ]));

    $this->actingAs(userWithPermissions(['stock_movements.view']))
        ->get('/inventory/stock-movements?filter[product_id]='.$productId)
        ->assertInertia(function (Assert $page) use ($gr, $do, $sa): void {
            $page->has('items.data', 4)->where('items.data', function ($rows) use ($gr, $do, $sa): bool {
                $numbers = collect($rows)->mapWithKeys(fn ($row): array => [$row['source_type'] => $row['source_number']]);

                return $numbers['goods_receipt'] === $gr->number
                    && $numbers['delivery_order'] === $do->number
                    && $numbers['delivery_order_void'] === $do->number
                    && $numbers['stock_adjustment'] === $sa->number;
            });
        });
});

test('stock card rows expose the document number too', function (): void {
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();
    $gr = (new GoodsReceiptService(new StockService))->post(
        AccountingFixtures::draftReceipt([['product' => $productId, 'quantity' => '10.0000', 'cost' => '100.0000']], $warehouseId)
    );

    $this->actingAs(userWithPermissions(['stock_movements.view']))
        ->get("/inventory/stock-card?product_id={$productId}&warehouse_id={$warehouseId}")
        ->assertInertia(fn (Assert $page) => $page->where('rows.data.0.source_number', $gr->number));
});

test('resolving document numbers does not add a query per movement', function (): void {
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();
    $service = new GoodsReceiptService(new StockService);
    $this->actingAs(userWithPermissions(['stock_movements.view']));

    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/inventory/stock-movements')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $service->post(AccountingFixtures::draftReceipt([['product' => $productId, 'quantity' => '1.0000', 'cost' => '10.0000']], $warehouseId));
    $countQueries(); // the first request also loads the user's permissions
    $withOne = $countQueries();

    foreach (range(1, 4) as $ignored) {
        $service->post(AccountingFixtures::draftReceipt([['product' => $productId, 'quantity' => '1.0000', 'cost' => '10.0000']], $warehouseId));
    }

    expect($countQueries())->toBe($withOne);
});
