<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\StockMovementType;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Reports\InventoryReconciliationReport;
use App\Services\Reports\InventorySummaryReport;
use App\Services\Reports\PurchasingSummaryReport;
use App\Services\Reports\ReportFilter;
use App\Services\Reports\SalesSummaryReport;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchaseInvoiceFixtures;
use Tests\Support\ReportFixtures;
use Tests\Support\SalesInvoiceFixtures;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
});

test('stock value agrees with the inventory account after a posted receipt', function (): void {
    $order = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '3.0000', 'unit_price' => '150.00']]);
    PurchaseInvoiceFixtures::postedReceipt($order, ['3.0000']);

    $result = (new InventoryReconciliationReport)->handle(new ReportFilter);

    expect($result->summary['stock_value'])->toBe('450.00')
        ->and($result->summary['ledger_balance'])->toBe('450.00')
        ->and($result->summary['difference'])->toBe('0.00')
        ->and($result->summary['status'])->toBe('matched')
        ->and($result->summary['account_code'])->toBe('1301')
        ->and($result->rows)->toHaveCount(1)
        ->and($result->rows[0]['warehouse_id'])->toBe($order->warehouse_id)
        ->and($result->rows[0]['stock_value'])->toBe('450.00');
});

test('stock that moved without a journal shows as a difference', function (): void {
    $product = TestDataFactory::createProduct(['track_stock' => true, 'is_active' => true]);
    $warehouse = TestDataFactory::createWarehouse();
    (new StockService)->increase($product, $warehouse, '2.0000', '100.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);

    $result = (new InventoryReconciliationReport)->handle(new ReportFilter);

    expect($result->summary['stock_value'])->toBe('200.00')
        ->and($result->summary['ledger_balance'])->toBe('0.00')
        ->and($result->summary['difference'])->toBe('200.00')
        ->and($result->summary['status'])->toBe('difference');
});

test('stock value is rounded per balance and totalled per warehouse', function (): void {
    $stock = new StockService;
    $first = TestDataFactory::createWarehouse(['code' => 'A-GUDANG']);
    $second = TestDataFactory::createWarehouse(['code' => 'B-GUDANG']);
    // 3 x 33.3333 = 99.9999 which rounds to 100.00, 3 x 0.3333 = 0.9999 which rounds to 1.00.
    $stock->increase(TestDataFactory::createProduct(['track_stock' => true]), $first, '3.0000', '33.3333', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);
    $stock->increase(TestDataFactory::createProduct(['track_stock' => true]), $first, '3.0000', '0.3333', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 2);
    $stock->increase(TestDataFactory::createProduct(['track_stock' => true]), $second, '1.0000', '5.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 3);

    $result = (new InventoryReconciliationReport)->handle(new ReportFilter);

    expect(array_column($result->rows, 'stock_value', 'warehouse_code'))->toBe(['A-GUDANG' => '101.00', 'B-GUDANG' => '5.00'])
        ->and($result->summary['stock_value'])->toBe('106.00');
});

test('the sales summary counts issued documents of the period only', function (): void {
    $order = SalesInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '500.00']]);
    SalesInvoiceFixtures::postedDelivery($order, ['1.0000']);
    SalesInvoiceFixtures::draftDelivery($order, ['1.0000']);
    SalesInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '999.00']], [], ['order_date' => '2026-09-01']);
    $customer = $order->customer_id;
    ReportFixtures::invoiceRow('sales_invoices', $customer, '2026-12-01', '100.00', '0.00', 'posted', '2026-11-02');
    ReportFixtures::invoiceRow('sales_invoices', $customer, '2026-12-01', '200.00', '50.00', 'partially_paid', '2026-11-03');
    ReportFixtures::invoiceRow('sales_invoices', $customer, '2026-12-01', '400.00', '400.00', 'paid', '2026-11-04');
    ReportFixtures::invoiceRow('sales_invoices', $customer, '2026-12-01', '800.00', '0.00', 'void', '2026-11-04');
    ReportFixtures::invoiceRow('sales_invoices', $customer, '2026-12-01', '1600.00', '0.00', 'draft', '2026-11-04');
    ReportFixtures::invoiceRow('sales_invoices', $customer, '2026-12-01', '3200.00', '0.00', 'posted', '2026-10-31');

    $result = (new SalesSummaryReport)->handle(new ReportFilter(dateFrom: '2026-11-01', dateTo: '2026-11-30'));

    expect($result->summary)->toBe([
        'order_count' => 1,
        'order_value' => '1000.00',
        'delivery_count' => 1,
        'invoice_count' => 3,
        'invoice_value' => '700.00',
    ])->and($result->rows)->toHaveCount(1)
        ->and($result->rows[0]['party_id'])->toBe($customer)
        ->and($result->rows[0]['invoice_count'])->toBe(3)
        ->and($result->rows[0]['value'])->toBe('700.00');
});

test('the purchasing summary mirrors the sales one', function (): void {
    $order = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '500.00']]);
    PurchaseInvoiceFixtures::postedReceipt($order, ['1.0000']);
    PurchaseInvoiceFixtures::draftReceipt($order, ['1.0000']);
    ReportFixtures::invoiceRow('purchase_invoices', $order->supplier_id, '2026-12-01', '100.00', '0.00', 'posted', '2026-11-02');
    ReportFixtures::invoiceRow('purchase_invoices', $order->supplier_id, '2026-12-01', '800.00', '0.00', 'void', '2026-11-02');

    $result = (new PurchasingSummaryReport)->handle(new ReportFilter(dateFrom: '2026-11-01', dateTo: '2026-11-30'));

    expect($result->summary)->toBe([
        'order_count' => 1,
        'order_value' => '1000.00',
        'receipt_count' => 1,
        'invoice_count' => 1,
        'invoice_value' => '100.00',
    ])->and($result->rows[0]['party_id'])->toBe($order->supplier_id)
        ->and($result->rows[0]['value'])->toBe('100.00');
});

test('the summaries list the ten biggest parties, largest first', function (string $report, string $table, string $factory): void {
    foreach (range(1, 12) as $i) {
        $party = TestDataFactory::{$factory}(['name' => sprintf('Mitra %02d', $i)]);
        ReportFixtures::invoiceRow($table, $party, '2026-12-01', sprintf('%d.00', $i * 100), '0.00', 'posted', '2026-11-02');
    }

    $rows = (new $report)->handle(new ReportFilter(dateFrom: '2026-11-01', dateTo: '2026-11-30'))->rows;

    expect($rows)->toHaveCount(10)
        ->and($rows[0]['party_name'])->toBe('Mitra 12')
        ->and($rows[0]['value'])->toBe('1200.00')
        ->and($rows[9]['party_name'])->toBe('Mitra 03');
})->with([
    'customers' => [SalesSummaryReport::class, 'sales_invoices', 'createCustomer'],
    'suppliers' => [PurchasingSummaryReport::class, 'purchase_invoices', 'createSupplier'],
]);

test('the summaries need a valid period', function (string $report): void {
    expect(fn () => (new $report)->handle(new ReportFilter(dateFrom: '2026-11-30', dateTo: '2026-11-01')))
        ->toThrow('Tanggal akhir tidak boleh sebelum tanggal awal.');
})->with([SalesSummaryReport::class, PurchasingSummaryReport::class]);

/**
 * @return array{low: int, fine: int, first: int, second: int}
 */
function stockedProducts(): array
{
    $stock = new StockService;
    $first = TestDataFactory::createWarehouse(['code' => 'A-GUDANG', 'name' => 'Gudang A']);
    $second = TestDataFactory::createWarehouse(['code' => 'B-GUDANG', 'name' => 'Gudang B']);
    $low = TestDataFactory::createProduct(['sku' => 'SKU-LOW', 'name' => 'Rendah', 'track_stock' => true, 'min_stock' => '10.0000']);
    $fine = TestDataFactory::createProduct(['sku' => 'SKU-FINE', 'name' => 'Aman', 'track_stock' => true, 'min_stock' => '5.0000']);
    $stock->increase($low, $first, '4.0000', '100.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);
    $stock->increase($fine, $first, '5.0000', '100.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 2);
    $stock->increase($fine, $second, '1.0000', '100.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 3);

    return compact('low', 'fine', 'first', 'second');
}

test('the inventory summary lists stock per warehouse and product and flags the low ones', function (): void {
    stockedProducts();

    $result = (new InventorySummaryReport)->handle(new ReportFilter);

    expect(array_map(fn (array $row): array => [$row['warehouse_code'], $row['sku'], $row['quantity'], $row['min_stock'], $row['below_minimum']], $result->rows))->toBe([
        ['A-GUDANG', 'SKU-FINE', '5.0000', '5.0000', false],
        ['A-GUDANG', 'SKU-LOW', '4.0000', '10.0000', true],
        ['B-GUDANG', 'SKU-FINE', '1.0000', '5.0000', true],
    ])->and($result->summary)->toBe([
        'product_count' => 2,
        'warehouse_count' => 2,
        'below_minimum_count' => 2,
    ]);
});

test('the inventory summary shows no cost or value', function (): void {
    stockedProducts();

    $row = (new InventorySummaryReport)->handle(new ReportFilter)->rows[0];

    expect(array_keys($row))->toBe(['warehouse_id', 'warehouse_code', 'warehouse_name', 'product_id', 'sku', 'product_name', 'quantity', 'min_stock', 'below_minimum']);
});

test('the inventory summary narrows by warehouse and to low stock, and pages its rows', function (): void {
    ['first' => $first] = stockedProducts();

    $byWarehouse = (new InventorySummaryReport)->handle(new ReportFilter(warehouseId: $first));
    $low = (new InventorySummaryReport)->handle(new ReportFilter(belowMinimumOnly: true));
    $page = (new InventorySummaryReport)->handle(new ReportFilter(page: 2, perPage: 2));

    expect(array_column($byWarehouse->rows, 'sku'))->toBe(['SKU-FINE', 'SKU-LOW'])
        ->and($byWarehouse->summary['warehouse_count'])->toBe(1)
        ->and(array_column($low->rows, 'below_minimum'))->toBe([true, true])
        ->and($page->rows)->toHaveCount(1)
        ->and($page->meta)->toBe(['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2, 'from' => 3, 'to' => 3]);
});

test('a report reads without writing anything', function (): void {
    stockedProducts();
    $before = DB::table('stock_balances')->count() + DB::table('audit_logs')->count() + DB::table('journals')->count();

    (new InventoryReconciliationReport)->handle(new ReportFilter);
    (new InventorySummaryReport)->handle(new ReportFilter);

    expect(DB::table('stock_balances')->count() + DB::table('audit_logs')->count() + DB::table('journals')->count())->toBe($before);
});
