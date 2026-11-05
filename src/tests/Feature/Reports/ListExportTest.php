<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\StockMovementType;
use App\Models\User;
use App\Services\Accounting\PaymentService;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Services\Reports\CsvExporter;
use App\Services\Sales\SalesInvoiceService;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PurchaseInvoiceFixtures;
use Tests\Support\ReportFixtures;
use Tests\Support\SalesInvoiceFixtures;
use Tests\Support\TestDataFactory;

/**
 * @return array<string, array{uri: string, permission: string, file: string, header: string}>
 */
function exportLists(): array
{
    return [
        'products' => ['uri' => '/master/products', 'permission' => 'products.view', 'file' => 'produk', 'header' => 'SKU,Nama,Kategori,Satuan,"Harga Jual","Stok Minimum","Lacak Stok",Aktif'],
        'customers' => ['uri' => '/master/customers', 'permission' => 'customers.view', 'file' => 'pelanggan', 'header' => 'Kode,Nama,Kontak,Telepon,"Limit Kredit","Syarat Bayar",Aktif'],
        'suppliers' => ['uri' => '/master/suppliers', 'permission' => 'suppliers.view', 'file' => 'pemasok', 'header' => 'Kode,Nama,Kontak,Telepon,"Syarat Bayar",Aktif'],
        'stock balances' => ['uri' => '/inventory/stock-balances', 'permission' => 'stock_balances.view', 'file' => 'saldo-stok', 'header' => 'Gudang,SKU,Produk,Kategori,Satuan,Stok,"Biaya Rata-rata","Nilai Total"'],
        'stock movements' => ['uri' => '/inventory/stock-movements', 'permission' => 'stock_movements.view', 'file' => 'mutasi-stok', 'header' => 'Tanggal,Jenis,SKU,Produk,Gudang,Kuantitas,"Biaya Satuan","Saldo Setelah","Biaya Rata-rata Setelah"'],
        'purchase orders' => ['uri' => '/purchasing/purchase-orders', 'permission' => 'purchase_orders.view', 'file' => 'pesanan-pembelian', 'header' => 'Nomor,Tanggal,Pemasok,Gudang,Status,Total'],
        'goods receipts' => ['uri' => '/purchasing/goods-receipts', 'permission' => 'goods_receipts.view', 'file' => 'penerimaan-barang', 'header' => 'Nomor,Tanggal,"No. Pesanan",Pemasok,Gudang,Status'],
        'purchase invoices' => ['uri' => '/purchasing/invoices', 'permission' => 'purchase_invoices.view', 'file' => 'faktur-pembelian', 'header' => 'Nomor,"No. Faktur Pemasok",Tanggal,"Jatuh Tempo",Pemasok,Total,Dibayar,Sisa,Status'],
        'sales orders' => ['uri' => '/sales/sales-orders', 'permission' => 'sales_orders.view', 'file' => 'pesanan-penjualan', 'header' => 'Nomor,Tanggal,Pelanggan,Gudang,Status,Total'],
        'delivery orders' => ['uri' => '/sales/delivery-orders', 'permission' => 'delivery_orders.view', 'file' => 'surat-jalan', 'header' => 'Nomor,Tanggal,"No. Pesanan",Pelanggan,Gudang,Status'],
        'sales invoices' => ['uri' => '/sales/invoices', 'permission' => 'sales_invoices.view', 'file' => 'faktur-penjualan', 'header' => 'Nomor,Tanggal,"Jatuh Tempo",Pelanggan,Total,Dibayar,Sisa,Status'],
        'payments' => ['uri' => '/accounting/payments', 'permission' => 'payments.view', 'file' => 'pembayaran', 'header' => 'Nomor,Tanggal,Jenis,Pihak,Metode,Akun,Referensi,Jumlah,Status'],
        'journals' => ['uri' => '/accounting/journals', 'permission' => 'journals.view', 'file' => 'jurnal', 'header' => 'Nomor,Tanggal,Deskripsi,Sumber,Debit,Kredit,Status'],
    ];
}

/**
 * @return list<string>
 */
function exportLines(object $response): array
{
    return array_values(array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $response->streamedContent())), fn (string $line): bool => $line !== ''));
}

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    Carbon::setTestNow('2026-11-05 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('a guest cannot export', function (string $name): void {
    $this->get(exportLists()[$name]['uri'].'?export=csv')->assertRedirect('/login');
})->with(array_keys(exportLists()));

test('a list exports only for those who may view it, as a named csv with its header', function (string $name): void {
    $list = exportLists()[$name];

    $this->actingAs(userWithPermissions(['units.view']));
    $this->get($list['uri'].'?export=csv')->assertForbidden();

    $this->flushSession();
    $this->actingAs(userWithPermissions([$list['permission']]));

    $response = $this->get($list['uri'].'?export=csv')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="'.$list['file'].'-2026-11-05.csv"');

    expect($response->streamedContent())->toStartWith("\xEF\xBB\xBF")
        ->and(exportLines($response)[0])->toBe($list['header']);
})->with(array_keys(exportLists()));

test('without an export request the list page stays as it was', function (string $name): void {
    $list = exportLists()[$name];
    $this->actingAs(userWithPermissions([$list['permission']]));

    $this->get($list['uri'])->assertOk()->assertInertia(fn (Assert $page) => $page->has('items.data'));
    $this->get($list['uri'].'?export=pdf')->assertOk()->assertInertia(fn (Assert $page) => $page->has('items.data'));
})->with(array_keys(exportLists()));

test('the export covers every matching row, not one page, in the order of the list', function (): void {
    $this->actingAs(userWithPermissions(['customers.view']));

    foreach (range(1, 30) as $i) {
        TestDataFactory::createCustomer(['code' => sprintf('C%03d', $i), 'name' => sprintf('Pelanggan %03d', $i), 'credit_limit' => 0]);
    }

    $lines = exportLines($this->get('/master/customers?export=csv&sort=code&direction=asc&per_page=5'));

    expect($lines)->toHaveCount(31)
        ->and($lines[1])->toStartWith('C001,"Pelanggan 001"')
        ->and($lines[30])->toStartWith('C030,"Pelanggan 030"');
});

test('the export follows the search and filters of the list', function (): void {
    $this->actingAs(userWithPermissions(['customers.view']));
    TestDataFactory::createCustomer(['code' => 'AKTIF', 'name' => 'Toko Maju', 'is_active' => true, 'credit_limit' => 0]);
    TestDataFactory::createCustomer(['code' => 'LAMA', 'name' => 'Toko Maju Lama', 'is_active' => false, 'credit_limit' => 0]);
    TestDataFactory::createCustomer(['code' => 'LAIN', 'name' => 'Warung Sejahtera', 'is_active' => true, 'credit_limit' => 0]);

    $searched = exportLines($this->get('/master/customers?export=csv&search=Maju&sort=code&direction=asc'));
    $filtered = exportLines($this->get('/master/customers?export=csv&search=Maju&filter[is_active]=1'));

    expect($searched)->toHaveCount(3)
        ->and($filtered)->toHaveCount(2)
        ->and($filtered[1])->toStartWith('AKTIF,');
});

test('stored text cannot become a spreadsheet formula', function (): void {
    $this->actingAs(userWithPermissions(['customers.view']));
    TestDataFactory::createCustomer(['code' => 'JAHAT', 'name' => '=HYPERLINK("http://contoh.test")', 'credit_limit' => 0]);

    expect(exportLines($this->get('/master/customers?export=csv'))[1])->toContain("\"'=HYPERLINK(\"\"http://contoh.test\"\")\"");
});

test('an export over the limit answers 422 instead of cutting rows', function (): void {
    $this->actingAs(userWithPermissions(['customers.view']));
    TestDataFactory::createCustomer(['credit_limit' => 0]);
    TestDataFactory::createCustomer(['credit_limit' => 0]);
    $this->app->instance(CsvExporter::class, new CsvExporter(maxRows: 1));

    $this->get('/master/customers?export=csv')
        ->assertStatus(422)
        ->assertSee('Data terlalu banyak untuk diekspor');
});

test('the rows carry the exact values of their documents', function (): void {
    $this->actingAs(User::factory()->create());

    $purchase = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '2.0000', 'unit_price' => '500.00']], ['name' => 'Pemasok Uji']);
    $receipt = PurchaseInvoiceFixtures::postedReceipt($purchase, ['2.0000']);
    $purchaseInvoice = (new PurchaseInvoiceService)->post(PurchaseInvoiceFixtures::draftInvoice($purchase, [$receipt], null, ['supplier_invoice_number' => 'INV-77']));

    $sale = SalesInvoiceFixtures::approvedOrder([['quantity' => '1.0000', 'unit_price' => '1000.00']], ['name' => 'Pelanggan Uji']);
    $delivery = SalesInvoiceFixtures::postedDelivery($sale, ['1.0000']);
    $salesInvoice = (new SalesInvoiceService)->post(SalesInvoiceFixtures::draftInvoice($sale, [$delivery]));

    $payments = new PaymentService;
    $payment = $payments->post($payments->createReceipt([
        'customer_id' => $sale->customer_id,
        'payment_date' => '2026-11-04',
        'method' => 'cash',
        'account_id' => ReportFixtures::accountId('1101'),
        'amount' => '400.00',
        'reference' => 'KW-1',
        'allocations' => [['sales_invoice_id' => $salesInvoice->id, 'amount' => '400.00']],
    ]));

    $this->flushSession();
    $this->actingAs(userWithPermissions(['purchase_orders.view', 'goods_receipts.view', 'purchase_invoices.view', 'sales_orders.view', 'delivery_orders.view', 'sales_invoices.view', 'payments.view']));

    expect(exportLines($this->get('/purchasing/purchase-orders?export=csv'))[1])->toEndWith('"Pemasok Uji","Warehouse Test",Diterima,1000.00')
        ->and(exportLines($this->get('/purchasing/goods-receipts?export=csv'))[1])->toBe("{$receipt->number},2026-11-01,{$purchase->number},\"Pemasok Uji\",\"Warehouse Test\",Diposting")
        ->and(exportLines($this->get('/purchasing/invoices?export=csv'))[1])->toBe("{$purchaseInvoice->number},INV-77,2026-11-03,{$purchaseInvoice->due_date->toDateString()},\"Pemasok Uji\",1000.00,0.00,1000.00,Diposting")
        ->and(exportLines($this->get('/sales/sales-orders?export=csv'))[1])->toEndWith('"Pelanggan Uji","Warehouse Test",'.$sale->fresh()->status->label().',1000.00')
        ->and(exportLines($this->get('/sales/delivery-orders?export=csv'))[1])->toBe("{$delivery->number},2026-11-01,{$sale->number},\"Pelanggan Uji\",\"Warehouse Test\",Diposting")
        ->and(exportLines($this->get('/sales/invoices?export=csv'))[1])->toBe("{$salesInvoice->number},2026-11-03,{$salesInvoice->due_date->toDateString()},\"Pelanggan Uji\",1000.00,400.00,600.00,\"Dibayar Sebagian\"")
        ->and(exportLines($this->get('/accounting/payments?export=csv'))[1])->toBe("{$payment->number},2026-11-04,Penerimaan,\"Pelanggan Uji\",Tunai,\"1101 Kas\",KW-1,400.00,Diposting");
});

test('stock lists export the quantities and costs they show', function (): void {
    $this->actingAs(userWithPermissions(['stock_balances.view', 'stock_movements.view']));
    $warehouse = TestDataFactory::createWarehouse(['name' => 'Gudang A']);
    $product = TestDataFactory::createProduct(['sku' => 'SKU-1', 'name' => 'Barang', 'track_stock' => true]);
    (new StockService)->increase($product, $warehouse, '3.0000', '33.3333', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);

    expect(exportLines($this->get('/inventory/stock-balances?export=csv'))[1])->toEndWith(',3.0000,33.3333,100.00')
        ->and(exportLines($this->get('/inventory/stock-balances?export=csv'))[1])->toStartWith('"Gudang A",SKU-1,Barang')
        ->and(exportLines($this->get('/inventory/stock-movements?export=csv'))[1])->toEndWith(',SKU-1,Barang,"Gudang A",3.0000,33.3333,3.0000,33.3333');
});

test('journals export their totals and source', function (): void {
    $this->actingAs(userWithPermissions(['journals.view']));
    $journal = ReportFixtures::journal('2026-11-03', [['1101', '100.00', '0.00'], ['3101', '0.00', '100.00']], true, 'Setoran');

    expect(exportLines($this->get('/accounting/journals?export=csv'))[1])->toBe("{$journal->number},2026-11-03,Setoran,Manual,100.00,100.00,Diposting");
});

test('an export keeps a small number of queries on many rows and writes nothing', function (): void {
    $this->actingAs(userWithPermissions(['products.view']));

    foreach (range(1, 60) as $i) {
        TestDataFactory::createProduct();
    }

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $response = $this->get('/master/products?export=csv');
    $response->streamedContent();

    expect(exportLines($response))->toHaveCount(61)
        ->and(count($statements))->toBeLessThanOrEqual(14)
        ->and(array_filter($statements, fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql) === 1))->toBe([]);
});
