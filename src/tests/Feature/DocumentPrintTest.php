<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\User;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\PurchaseInvoiceService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PurchaseInvoiceFixtures;
use Tests\Support\ReportFixtures;
use Tests\Support\SalesInvoiceFixtures;
use Tests\Support\TestDataFactory;

/**
 * @return array<string, array{uri: string, permission: string, component: string}>
 */
function printRoutes(): array
{
    return [
        'purchase order' => ['uri' => '/purchasing/purchase-orders/%d/print', 'permission' => 'purchase_orders.view', 'component' => 'Purchasing/PurchaseOrders/Print'],
        'goods receipt' => ['uri' => '/purchasing/goods-receipts/%d/print', 'permission' => 'goods_receipts.view', 'component' => 'Purchasing/GoodsReceipts/Print'],
        'purchase invoice' => ['uri' => '/purchasing/invoices/%d/print', 'permission' => 'purchase_invoices.view', 'component' => 'Purchasing/Invoices/Print'],
        'sales order' => ['uri' => '/sales/sales-orders/%d/print', 'permission' => 'sales_orders.view', 'component' => 'Sales/SalesOrders/Print'],
        'delivery order' => ['uri' => '/sales/delivery-orders/%d/print', 'permission' => 'delivery_orders.view', 'component' => 'Sales/DeliveryOrders/Print'],
        'stock adjustment' => ['uri' => '/inventory/stock-adjustments/%d/print', 'permission' => 'stock_adjustments.view', 'component' => 'Inventory/StockAdjustments/Print'],
        'journal' => ['uri' => '/accounting/journals/%d/print', 'permission' => 'journals.view', 'component' => 'Accounting/Journals/Print'],
    ];
}

/**
 * @return array<string, int> a document of each kind, with its id
 */
function printableDocuments(): array
{
    $purchase = PurchaseInvoiceFixtures::approvedOrder(
        [['quantity' => '4.0000', 'unit_price' => '777.00', 'discount_amount' => '8.00']],
        ['name' => 'Pemasok Cetak', 'address' => 'Jl. Pemasok 1'],
    );
    $receipt = PurchaseInvoiceFixtures::postedReceipt($purchase, ['4.0000']);
    $purchaseInvoice = (new PurchaseInvoiceService)->post(PurchaseInvoiceFixtures::draftInvoice($purchase, [$receipt]));

    $sale = SalesInvoiceFixtures::approvedOrder(
        [['quantity' => '3.0000', 'unit_price' => '555.00', 'discount_amount' => '5.00']],
        ['name' => 'Pelanggan Cetak', 'address' => 'Jl. Pelanggan 2'],
    );
    $delivery = SalesInvoiceFixtures::postedDelivery($sale, ['3.0000']);

    $warehouse = TestDataFactory::createWarehouse(['name' => 'Gudang Cetak']);
    $product = TestDataFactory::createProduct(['sku' => 'SKU-CETAK', 'name' => 'Barang Cetak', 'track_stock' => true]);
    (new StockService)->increase($product, $warehouse, '10.0000', '333.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);
    $adjustments = new StockAdjustmentService(new StockService);
    $adjustment = $adjustments->post($adjustments->create([
        'warehouse_id' => $warehouse,
        'adjustment_date' => '2026-11-02',
        'reason' => 'Stock opname',
        'items' => [['product_id' => $product, 'counted_quantity' => '8.0000']],
    ]));

    $journal = ReportFixtures::journal('2026-11-03', [['1101', '100.00', '0.00'], ['3101', '0.00', '100.00']], true, 'Setoran kas');

    return [
        'purchase order' => $purchase->id,
        'goods receipt' => $receipt->id,
        'purchase invoice' => $purchaseInvoice->id,
        'sales order' => $sale->id,
        'delivery order' => $delivery->id,
        'stock adjustment' => $adjustment->id,
        'journal' => $journal->id,
    ];
}

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
    $this->documents = printableDocuments();
    $this->flushSession();
    auth()->logout();
});

test('a guest is sent to the login page', function (string $name): void {
    $this->get(sprintf(printRoutes()[$name]['uri'], $this->documents[$name]))->assertRedirect('/login');
})->with(array_keys(printRoutes()));

test('a document prints only for those who may view it, and writes nothing', function (string $name): void {
    $route = printRoutes()[$name];
    $uri = sprintf($route['uri'], $this->documents[$name]);

    $this->actingAs(userWithPermissions(['products.view']));
    $this->get($uri)->assertForbidden();

    $this->flushSession();
    $this->actingAs(userWithPermissions([$route['permission']]));

    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $this->get($uri)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component($route['component'])
        ->where('item.id', $this->documents[$name]));

    expect($writes)->toBe([]);
})->with(array_keys(printRoutes()));

test('an unknown document is not found', function (string $name): void {
    $route = printRoutes()[$name];
    $this->actingAs(userWithPermissions([$route['permission']]));

    $this->get(sprintf($route['uri'], 999999999))->assertNotFound();
})->with(array_keys(printRoutes()));

test('a purchase order prints its party, prices and totals', function (): void {
    $this->actingAs(userWithPermissions(['purchase_orders.view']));

    $this->get("/purchasing/purchase-orders/{$this->documents['purchase order']}/print")->assertInertia(fn (Assert $page) => $page
        ->where('item.supplier.name', 'Pemasok Cetak')
        ->where('item.supplier.address', 'Jl. Pemasok 1')
        ->where('item.orderDate', '2026-11-01')
        ->where('item.items.0.lineNo', 1)
        ->where('item.items.0.quantity', '4.0000')
        ->where('item.items.0.unitPrice', '777.0000')
        ->where('item.items.0.discountAmount', '8.00')
        ->where('item.items.0.lineTotal', '3100.00')
        ->where('item.total', '3100.00')
        ->has('item.items.0.unit')
        ->has('item.warehouse'));
});

test('a sales order prints its party, prices and totals', function (): void {
    $this->actingAs(userWithPermissions(['sales_orders.view']));

    $this->get("/sales/sales-orders/{$this->documents['sales order']}/print")->assertInertia(fn (Assert $page) => $page
        ->where('item.customer.name', 'Pelanggan Cetak')
        ->where('item.customer.address', 'Jl. Pelanggan 2')
        ->where('item.items.0.unitPrice', '555.0000')
        ->where('item.items.0.lineTotal', '1660.00')
        ->where('item.total', '1660.00'));
});

test('warehouse documents send quantities and never a price or cost', function (string $name, string $amount): void {
    $route = printRoutes()[$name];
    $this->actingAs(userWithPermissions([$route['permission']]));

    $response = $this->get(sprintf($route['uri'], $this->documents[$name]))->assertOk();
    $item = $response->viewData('page')['props']['item'];
    $payload = (string) json_encode($item);

    expect($payload)->not->toContain($amount)
        ->and($payload)->not->toContain('unit_cost')
        ->and($payload)->not->toContain('unitCost')
        ->and($payload)->not->toContain('unitPrice')
        ->and($payload)->not->toContain('unit_price')
        ->and($payload)->not->toContain('avg_cost')
        ->and($payload)->not->toContain('total');
})->with([
    'delivery order' => ['delivery order', '555'],
    'goods receipt' => ['goods receipt', '777'],
    'stock adjustment' => ['stock adjustment', '333'],
]);

test('a delivery order prints who gets what, from where', function (): void {
    $this->actingAs(userWithPermissions(['delivery_orders.view']));

    $this->get("/sales/delivery-orders/{$this->documents['delivery order']}/print")->assertInertia(fn (Assert $page) => $page
        ->where('item.customer.name', 'Pelanggan Cetak')
        ->where('item.shippingAddress', 'Jl. Pelanggan 2')
        ->where('item.deliveryDate', '2026-11-01')
        ->where('item.salesOrder.id', $this->documents['sales order'])
        ->where('item.items.0.quantity', '3.0000')
        ->has('item.warehouse.name'));
});

test('a goods receipt prints its supplier and quantities', function (): void {
    $this->actingAs(userWithPermissions(['goods_receipts.view']));

    $this->get("/purchasing/goods-receipts/{$this->documents['goods receipt']}/print")->assertInertia(fn (Assert $page) => $page
        ->where('item.supplier.name', 'Pemasok Cetak')
        ->where('item.receiptDate', '2026-11-01')
        ->where('item.purchaseOrder.id', $this->documents['purchase order'])
        ->where('item.items.0.quantity', '4.0000'));
});

test('a stock adjustment prints what was counted', function (): void {
    $this->actingAs(userWithPermissions(['stock_adjustments.view']));

    $this->get("/inventory/stock-adjustments/{$this->documents['stock adjustment']}/print")->assertInertia(fn (Assert $page) => $page
        ->where('item.warehouse.name', 'Gudang Cetak')
        ->where('item.reason', 'Stock opname')
        ->where('item.adjustmentDate', '2026-11-02')
        ->where('item.items.0.sku', 'SKU-CETAK')
        ->where('item.items.0.name', 'Barang Cetak')
        ->where('item.items.0.systemQuantity', '10.0000')
        ->where('item.items.0.countedQuantity', '8.0000')
        ->where('item.items.0.differenceQuantity', '-2.0000'));
});

test('a journal prints its accounts and totals', function (): void {
    $this->actingAs(userWithPermissions(['journals.view']));

    $this->get("/accounting/journals/{$this->documents['journal']}/print")->assertInertia(fn (Assert $page) => $page
        ->where('item.description', 'Setoran kas')
        ->where('item.journalDate', '2026-11-03')
        ->where('item.sourceLabel', null)
        ->where('item.lines.0.code', '1101')
        ->where('item.lines.0.debit', '100.00')
        ->where('item.lines.1.credit', '100.00')
        ->where('item.totalDebit', '100.00')
        ->where('item.totalCredit', '100.00'));
});

test('a purchase invoice prints the same detail as its page', function (): void {
    $this->actingAs(userWithPermissions(['purchase_invoices.view']));

    $this->get("/purchasing/invoices/{$this->documents['purchase invoice']}/print")->assertInertia(fn (Assert $page) => $page
        ->where('item.supplier.name', 'Pemasok Cetak')
        ->where('item.total', '3100.00')
        ->where('item.items.0.lineTotal', '3100.00')
        ->has('item.supplierInvoiceNumber'));
});
