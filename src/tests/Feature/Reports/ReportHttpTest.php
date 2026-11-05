<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\StockMovementType;
use App\Services\Inventory\StockService;
use App\Services\Reports\CsvExporter;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportFixtures;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    config(['inertia.testing.ensure_pages_exist' => false]);
    (new ChartOfAccountSeeder)->run();
    (new RoleSeeder)->run();
    Carbon::setTestNow('2026-11-05 10:00:00');
    $this->customerId = TestDataFactory::createCustomer(['code' => 'CST-A', 'name' => 'Pelanggan A', 'credit_limit' => 0]);
    $this->cash = ReportFixtures::accountId('1101');
    $this->period = 'date_from=2026-10-01&date_to=2026-10-31';
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array<string, array{uri: string, permission: string, component: string}>
 */
function reportRoutes(): array
{
    return [
        'trial-balance' => ['uri' => '/reports/trial-balance', 'permission' => 'reports.view_finance', 'component' => 'Reports/TrialBalance'],
        'general-ledger' => ['uri' => '/reports/general-ledger', 'permission' => 'reports.view_finance', 'component' => 'Reports/GeneralLedger'],
        'income-statement' => ['uri' => '/reports/income-statement', 'permission' => 'reports.view_finance', 'component' => 'Reports/IncomeStatement'],
        'balance-sheet' => ['uri' => '/reports/balance-sheet', 'permission' => 'reports.view_finance', 'component' => 'Reports/BalanceSheet'],
        'receivable-aging' => ['uri' => '/reports/receivable-aging', 'permission' => 'reports.view_finance', 'component' => 'Reports/ReceivableAging'],
        'payable-aging' => ['uri' => '/reports/payable-aging', 'permission' => 'reports.view_finance', 'component' => 'Reports/PayableAging'],
        'customer-statement' => ['uri' => '/reports/customer-statement', 'permission' => 'reports.view_finance', 'component' => 'Reports/CustomerStatement'],
        'inventory-reconciliation' => ['uri' => '/reports/inventory-reconciliation', 'permission' => 'reports.view_finance', 'component' => 'Reports/InventoryReconciliation'],
        'sales-summary' => ['uri' => '/reports/sales-summary', 'permission' => 'reports.view_sales', 'component' => 'Reports/SalesSummary'],
        'purchasing-summary' => ['uri' => '/reports/purchasing-summary', 'permission' => 'reports.view_purchasing', 'component' => 'Reports/PurchasingSummary'],
        'inventory-summary' => ['uri' => '/reports/inventory-summary', 'permission' => 'reports.view_inventory', 'component' => 'Reports/InventorySummary'],
    ];
}

/**
 * The query string that makes a report's export return data.
 */
function exportQueryOf(string $name, int $accountId, int $customerId): string
{
    return match ($name) {
        'general-ledger' => "export=csv&account_id={$accountId}&date_from=2026-10-01&date_to=2026-10-31",
        'customer-statement' => "export=csv&customer_id={$customerId}&date_from=2026-10-01&date_to=2026-10-31",
        default => 'export=csv&date_from=2026-10-01&date_to=2026-10-31',
    };
}

test('a guest is sent to the login page by every report', function (): void {
    foreach (reportRoutes() as $route) {
        $this->get($route['uri'])->assertRedirect('/login');
        $this->get($route['uri'].'?export=csv')->assertRedirect('/login');
    }
});

test('each report opens only with its own permission, for the page and the export alike', function (string $name): void {
    $route = reportRoutes()[$name];
    $others = array_values(array_diff(
        ['reports.view_finance', 'reports.view_sales', 'reports.view_purchasing', 'reports.view_inventory'],
        [$route['permission']]
    ));

    $this->actingAs(userWithPermissions($others));
    $this->get($route['uri'])->assertForbidden();
    $this->get($route['uri'].'?'.exportQueryOf($name, $this->cash, $this->customerId))->assertForbidden();

    $this->flushSession();
    $this->actingAs(userWithPermissions([$route['permission']]));
    $this->get($route['uri'])->assertOk()->assertInertia(fn (Assert $page) => $page->component($route['component']));
    $this->get($route['uri'].'?'.exportQueryOf($name, $this->cash, $this->customerId))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
})->with(array_keys(reportRoutes()));

test('each role reaches exactly the reports its permissions allow', function (string $role, array $allowed): void {
    $this->actingAs(userWithRole($role));

    foreach (reportRoutes() as $route) {
        $expected = in_array($route['permission'], $allowed, true) ? 200 : 403;

        $this->get($route['uri'])->assertStatus($expected);
    }
})->with([
    'admin' => ['admin', ['reports.view_finance', 'reports.view_sales', 'reports.view_purchasing', 'reports.view_inventory']],
    'finance' => ['finance', ['reports.view_finance', 'reports.view_sales', 'reports.view_purchasing']],
    'purchasing' => ['purchasing', ['reports.view_purchasing']],
    'sales' => ['sales', ['reports.view_sales']],
    'warehouse' => ['warehouse', ['reports.view_inventory']],
    'viewer' => ['viewer', ['reports.view_finance', 'reports.view_sales', 'reports.view_purchasing', 'reports.view_inventory']],
]);

test('a period report opens on the current month and hands its filters back', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance']));
    ReportFixtures::journal('2026-11-02', [['1101', '100.00', '0.00'], ['3101', '0.00', '100.00']]);
    ReportFixtures::journal('2026-10-30', [['1101', '900.00', '0.00'], ['3101', '0.00', '900.00']]);

    $this->get('/reports/trial-balance')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/TrialBalance')
        ->where('filters.date_from', '2026-11-01')
        ->where('filters.date_to', '2026-11-05')
        ->where('summary.total_debit', '100.00')
        ->where('summary.opening_debit', '900.00')
        ->where('summary.balanced', true)
        ->has('rows', 2)
        ->where('rows.0.code', '1101')
        ->where('rows.0.closing', '1000.00'));
});

test('the ledger opens empty until an account is chosen, then shows it with paged rows', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance']));
    ReportFixtures::journal('2026-10-05', [['1101', '100.00', '0.00'], ['3101', '0.00', '100.00']]);

    $this->get('/reports/general-ledger?'.$this->period)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('rows', [])
        ->where('summary', null)
        ->where('meta', null)
        ->has('accounts')
        ->where('accounts', fn ($accounts): bool => collect($accounts)->every(fn ($a): bool => ! in_array($a['code'], ['1000', '2000'], true))));

    $this->get('/reports/general-ledger?'.$this->period."&account_id={$this->cash}&per_page=25")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('summary.account_code', '1101')
        ->where('summary.closing', '100.00')
        ->where('meta.per_page', 25)
        ->where('filters.account_id', $this->cash)
        ->has('rows', 1));
});

test('the statement opens empty until a customer is chosen, and offers former customers too', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance']));
    $former = TestDataFactory::createCustomer(['name' => 'Pelanggan Lama', 'is_active' => false, 'credit_limit' => 0]);

    $this->get('/reports/customer-statement?'.$this->period)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('rows', [])
        ->where('summary', null)
        ->where('customers', fn ($customers): bool => collect($customers)->contains('id', $former)));

    ReportFixtures::invoiceRow('sales_invoices', $this->customerId, '2026-11-20', '250.00', '0.00', 'posted', '2026-10-05');

    $this->get('/reports/customer-statement?'.$this->period."&customer_id={$this->customerId}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('summary.customer_code', 'CST-A')
        ->where('summary.closing', '250.00')
        ->where('rows.0.kind', 'invoice'));
});

test('the balance sheet reads at a chosen date and defaults to today', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance']));
    ReportFixtures::journal('2026-10-05', [['1101', '100.00', '0.00'], ['3101', '0.00', '100.00']]);

    $this->get('/reports/balance-sheet')->assertInertia(fn (Assert $page) => $page
        ->where('filters.as_of', '2026-11-05')
        ->where('summary.assets', '100.00')
        ->where('summary.balanced', true));

    $this->get('/reports/balance-sheet?as_of=2026-10-01')->assertInertia(fn (Assert $page) => $page
        ->where('filters.as_of', '2026-10-01')
        ->where('summary.assets', '0.00'));
});

test('aging shows today with invoice details', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance']));
    ReportFixtures::invoiceRow('sales_invoices', $this->customerId, '2026-11-01', '100.00');

    $this->get('/reports/receivable-aging')->assertInertia(fn (Assert $page) => $page
        ->component('Reports/ReceivableAging')
        ->where('summary.as_of', '2026-11-05')
        ->where('summary.days_1_30', '100.00')
        ->where('rows.0.party_code', 'CST-A')
        ->has('rows.0.invoices', 1));
});

test('the inventory summary filters by warehouse and low stock', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_inventory']));
    $warehouse = TestDataFactory::createWarehouse(['name' => 'Gudang Uji']);
    $product = TestDataFactory::createProduct(['track_stock' => true, 'min_stock' => '10.0000']);
    (new StockService)->increase($product, $warehouse, '1.0000', '100.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);

    $this->get("/reports/inventory-summary?warehouse_id={$warehouse}&below_minimum=1")->assertInertia(fn (Assert $page) => $page
        ->component('Reports/InventorySummary')
        ->where('filters.warehouse_id', $warehouse)
        ->where('filters.below_minimum', true)
        ->where('summary.below_minimum_count', 1)
        ->where('meta.total', 1)
        ->has('warehouses')
        ->where('rows.0.below_minimum', true));
});

test('the operational summaries and reconciliation fill their pages', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance', 'reports.view_sales', 'reports.view_purchasing']));
    ReportFixtures::invoiceRow('sales_invoices', $this->customerId, '2026-11-20', '250.00', '0.00', 'posted', '2026-10-05');

    $this->get('/reports/sales-summary?'.$this->period)->assertInertia(fn (Assert $page) => $page
        ->where('summary.invoice_value', '250.00')
        ->where('rows.0.party_code', 'CST-A'));
    $this->get('/reports/purchasing-summary?'.$this->period)->assertInertia(fn (Assert $page) => $page
        ->where('summary.receipt_count', 0)
        ->where('rows', []));
    $this->get('/reports/inventory-reconciliation')->assertInertia(fn (Assert $page) => $page
        ->where('summary.status', 'matched')
        ->where('summary.difference', '0.00'));
});

test('the filter is validated', function (string $uri, string $query, string $field): void {
    $this->actingAs(userWithPermissions(['reports.view_finance']));

    $this->get("{$uri}?{$query}", ['X-Inertia' => 'true'])->assertSessionHasErrors($field);
})->with([
    'bad start' => ['/reports/trial-balance', 'date_from=kemarin&date_to=2026-10-31', 'date_from'],
    'bad end' => ['/reports/trial-balance', 'date_from=2026-10-01&date_to=31-10-2026', 'date_to'],
    'end without start' => ['/reports/trial-balance', 'date_to=2026-10-31', 'date_from'],
    'start without end' => ['/reports/trial-balance', 'date_from=2026-10-01', 'date_to'],
    'reversed' => ['/reports/trial-balance', 'date_from=2026-10-31&date_to=2026-10-01', 'date_to'],
    'over five years' => ['/reports/trial-balance', 'date_from=2020-01-01&date_to=2026-10-01', 'date_to'],
    'bad balance date' => ['/reports/balance-sheet', 'as_of=31/10/2026', 'as_of'],
    'bad account' => ['/reports/general-ledger', 'account_id=abc', 'account_id'],
    'bad customer' => ['/reports/customer-statement', 'customer_id=abc', 'customer_id'],
    'unknown customer' => ['/reports/customer-statement', 'customer_id=999999999&date_from=2026-10-01&date_to=2026-10-31', 'customer_id'],
    'bad page size' => ['/reports/general-ledger', 'per_page=100000', 'per_page'],
    'unknown export' => ['/reports/trial-balance', 'export=pdf', 'export'],
]);

test('a header account has no ledger', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance']));
    $header = ReportFixtures::accountId('1000');

    $this->get("/reports/general-ledger?{$this->period}&account_id={$header}", ['X-Inertia' => 'true'])
        ->assertSessionHasErrors('account_id');
});

test('a plain request for an impossible export answers 422 in Indonesian', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance']));

    $this->get('/reports/general-ledger?export=csv&'.$this->period)
        ->assertStatus(422)
        ->assertSee('Akun tidak ditemukan.');
});

test('an export over the limit answers 422 with the reason', function (string $name): void {
    $this->actingAs(userWithPermissions(['reports.view_finance', 'reports.view_inventory']));
    ReportFixtures::journal('2026-10-05', [['1101', '100.00', '0.00'], ['3101', '0.00', '100.00']]);
    $warehouse = TestDataFactory::createWarehouse();
    (new StockService)->increase(TestDataFactory::createProduct(['track_stock' => true]), $warehouse, '1.0000', '1.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);
    (new StockService)->increase(TestDataFactory::createProduct(['track_stock' => true]), $warehouse, '1.0000', '1.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 2);
    $this->app->instance(CsvExporter::class, new CsvExporter(maxRows: 1));

    $this->get(reportRoutes()[$name]['uri'].'?'.exportQueryOf($name, $this->cash, $this->customerId))
        ->assertStatus(422)
        ->assertSee('Data terlalu banyak untuk diekspor');
})->with(['trial-balance', 'general-ledger', 'inventory-summary']);

/**
 * @return list<string>
 */
function csvLines(object $response): array
{
    return array_values(array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $response->streamedContent())), fn (string $line): bool => $line !== ''));
}

test('the trial balance export lists accounts with plain numbers', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance']));
    ReportFixtures::journal('2026-10-05', [['1101', '100.50', '0.00'], ['3101', '0.00', '100.50']]);
    ReportFixtures::journal('2026-10-06', [['1101', '0.00', '200.00'], ['3101', '200.00', '0.00']]);

    $response = $this->get('/reports/trial-balance?export=csv&'.$this->period);

    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="neraca-saldo-2026-10-01-2026-10-31.csv"')
        ->and(csvLines($response))->toBe([
            'Kode,Nama,"Saldo Normal","Saldo Awal",Debit,Kredit,"Saldo Akhir"',
            '1101,Kas,Debit,0.00,100.50,200.00,-99.50',
            '3101,"Modal Pemilik",Kredit,0.00,200.00,100.50,-99.50',
        ]);
});

test('the ledger export opens with the balance brought forward and guards journal text', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance']));
    ReportFixtures::journal('2026-09-05', [['1101', '50.00', '0.00'], ['3101', '0.00', '50.00']]);
    ReportFixtures::journal('2026-10-05', [['1101', '100.00', '0.00'], ['3101', '0.00', '100.00']], true, '=HYPERLINK("http://contoh.test")');

    $lines = csvLines($this->get("/reports/general-ledger?export=csv&account_id={$this->cash}&".$this->period));

    expect($lines[0])->toBe('Tanggal,"Nomor Jurnal",Deskripsi,Sumber,Debit,Kredit,Saldo')
        ->and($lines[1])->toBe('2026-10-01,,"Saldo awal",,,,50.00')
        ->and($lines[2])->toContain("\"'=HYPERLINK(\"\"http://contoh.test\"\")\"")
        ->and($lines[2])->toEndWith(',,100.00,0.00,150.00')
        ->and($lines)->toHaveCount(3);
});

test('the statements come out as section, account and amount, with totals', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance']));
    ReportFixtures::journal('2026-10-05', [['1201', '700.00', '0.00'], ['4201', '300.00', '0.00'], ['4101', '0.00', '1000.00']]);
    ReportFixtures::journal('2026-10-06', [['5101', '400.00', '0.00'], ['1101', '0.00', '400.00']]);

    expect(csvLines($this->get('/reports/income-statement?export=csv&'.$this->period)))->toBe([
        'Bagian,Kode,Nama,Jumlah',
        'Pendapatan,4101,Penjualan,1000.00',
        'Pendapatan,4201,"Potongan Penjualan",-300.00',
        'Pendapatan,,"Total Pendapatan",700.00',
        '"Beban Pokok",5101,"Harga Pokok Penjualan",400.00',
        '"Beban Pokok",,"Total Beban Pokok",400.00',
        '"Beban Operasional",,"Total Beban Operasional",0.00',
        'Laba,,"Laba Kotor",300.00',
        'Laba,,"Laba Bersih",300.00',
    ]);

    expect(csvLines($this->get('/reports/balance-sheet?export=csv&as_of=2026-10-31')))->toBe([
        'Bagian,Kode,Nama,Jumlah',
        'Aset,1101,Kas,-400.00',
        'Aset,1201,"Piutang Usaha",700.00',
        'Aset,,"Total Aset",300.00',
        'Liabilitas,,"Total Liabilitas",0.00',
        'Ekuitas,,"Laba Berjalan",300.00',
        'Ekuitas,,"Total Ekuitas",300.00',
        'Total,,"Total Liabilitas dan Ekuitas",300.00',
    ]);
});

test('aging, statement, reconciliation and summaries export their rows', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance', 'reports.view_sales', 'reports.view_inventory']));
    ReportFixtures::invoiceRow('sales_invoices', $this->customerId, '2026-11-01', '100.00', '0.00', 'posted', '2026-10-05');

    expect(csvLines($this->get('/reports/receivable-aging?export=csv')))->toBe([
        'Kode,Nama,"Belum Jatuh Tempo","1-30 Hari","31-60 Hari","61-90 Hari","> 90 Hari",Total',
        'CST-A,"Pelanggan A",0.00,100.00,0.00,0.00,0.00,100.00',
    ])->and(csvLines($this->get("/reports/customer-statement?export=csv&customer_id={$this->customerId}&".$this->period)))->toBe([
        'Tanggal,Jenis,Nomor,"Untuk Faktur",Debit,Kredit,Saldo',
        '2026-10-01,"Saldo Awal",,,,,0.00',
        '2026-10-05,Faktur,'.DB::table('sales_invoices')->value('number').',,100.00,0.00,100.00',
    ])->and(csvLines($this->get('/reports/inventory-reconciliation?export=csv')))->toBe([
        'Kode,Keterangan,Nilai',
        ',"Total Nilai Persediaan",0.00',
        '1301,"Saldo Akun Persediaan",0.00',
        ',Selisih,0.00',
    ])->and(csvLines($this->get('/reports/sales-summary?export=csv&'.$this->period)))->toBe([
        'Kode,Nama,"Jumlah Faktur",Nilai',
        'CST-A,"Pelanggan A",1,100.00',
    ]);
});

test('the inventory export stays free of cost', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_inventory']));
    $warehouse = TestDataFactory::createWarehouse(['code' => 'GD-A', 'name' => 'Gudang A']);
    $product = TestDataFactory::createProduct(['sku' => 'SKU-1', 'name' => 'Barang', 'track_stock' => true, 'min_stock' => '5.0000']);
    (new StockService)->increase($product, $warehouse, '1.0000', '777.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);

    expect(csvLines($this->get('/reports/inventory-summary?export=csv')))->toBe([
        'Gudang,SKU,Produk,Stok,Minimum,"Di Bawah Minimum"',
        '"Gudang A",SKU-1,Barang,1.0000,5.0000,Ya',
    ]);
});

test('reports keep a small fixed number of queries on 200 rows and write nothing', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance', 'reports.view_sales', 'reports.view_purchasing', 'reports.view_inventory']));
    $supplier = TestDataFactory::createSupplier();
    $stock = new StockService;
    $warehouse = TestDataFactory::createWarehouse();

    foreach (range(1, 200) as $i) {
        ReportFixtures::journal('2026-10-05', [['1101', '1.00', '0.00'], ['3101', '0.00', '1.00']]);
        ReportFixtures::invoiceRow('sales_invoices', $this->customerId, '2026-10-20', '10.00', '0.00', 'posted', '2026-10-05');
        ReportFixtures::invoiceRow('purchase_invoices', $supplier, '2026-10-20', '10.00', '0.00', 'posted', '2026-10-05');
        $stock->increase(TestDataFactory::createProduct(['track_stock' => true]), $warehouse, '1.0000', '1.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, $i);
    }

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    foreach (reportRoutes() as $name => $route) {
        $query = exportQueryOf($name, $this->cash, $this->customerId);

        foreach ([str_replace('export=csv&', '', $query), $query] as $suffix) {
            $statements = [];
            $response = $this->get($route['uri'].'?'.$suffix);
            $response->assertOk();

            if (str_contains($suffix, 'export=csv')) {
                $response->streamedContent();
            }

            expect(count($statements))->toBeLessThanOrEqual(14, "{$name} ran ".count($statements).' queries.');

            $writes = array_filter($statements, fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql) === 1);

            expect(array_values($writes))->toBe([], "{$name} must not write.");
        }
    }
});
