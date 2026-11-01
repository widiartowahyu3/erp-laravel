<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\GoodsReceipt;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Dashboard\DashboardSummary;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    // Ensure base permissions exist
    Permission::firstOrCreate(['name' => 'purchase_orders.view'], ['display_name' => 'Lihat PO']);
    Permission::firstOrCreate(['name' => 'goods_receipts.view'], ['display_name' => 'Lihat GR']);
    Permission::firstOrCreate(['name' => 'sales_orders.view'], ['display_name' => 'Lihat SO']);
    Permission::firstOrCreate(['name' => 'delivery_orders.view'], ['display_name' => 'Lihat DO']);
    Permission::firstOrCreate(['name' => 'stock_balances.view'], ['display_name' => 'Lihat Saldo']);
    Permission::firstOrCreate(['name' => 'stock_movements.view'], ['display_name' => 'Lihat Mutasi']);
});

test('guest is redirected to login', function (): void {
    $this->get('/')->assertRedirect('/login');
});

test('user without permissions receives null props for all summary sections', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('purchaseOrders', null)
            ->where('goodsReceipts', null)
            ->where('salesOrders', null)
            ->where('deliveryOrders', null)
            ->where('stockBalances', null)
            ->where('stockMovements', null)
        );
});

test('user with selective permissions receives only authorized section props', function (): void {
    $user = userWithPermissions(['purchase_orders.view', 'sales_orders.view']);

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('purchaseOrders')
            ->has('salesOrders')
            ->where('goodsReceipts', null)
            ->where('deliveryOrders', null)
            ->where('stockBalances', null)
            ->where('stockMovements', null)
        );
});

test('summary calculates exact figures on controlled fixtures including boundary min_stock, no-balance products, and decimal strings', function (): void {
    $user = userWithPermissions([
        'purchase_orders.view',
        'goods_receipts.view',
        'sales_orders.view',
        'delivery_orders.view',
        'stock_balances.view',
        'stock_movements.view',
    ]);

    $unit = Unit::firstOrCreate(['code' => 'PCS'], ['name' => 'Pieces']);
    $cat = ProductCategory::firstOrCreate(['code' => 'CAT-DASH'], ['name' => 'Kategori Dashboard']);
    $wh = Warehouse::firstOrCreate(['code' => 'WH-TEST-DASH'], ['name' => 'Gudang Uji Dashboard', 'is_active' => true]);
    $supp = Supplier::firstOrCreate(['code' => 'SUP-TEST-DASH'], ['name' => 'Pemasok Uji Dashboard', 'is_active' => true]);
    $cust = Customer::firstOrCreate(['code' => 'CUST-TEST-DASH'], ['name' => 'Pelanggan Uji Dashboard', 'is_active' => true]);

    // 1. Fixture Products & Stock Balances
    // P1: min_stock 10.0000, current balance 9.0000 (Shortage = 1.0000)
    $p1 = Product::create([
        'sku' => 'DASH-P1',
        'name' => 'Produk Dash 1',
        'category_id' => $cat->id,
        'base_unit_id' => $unit->id,
        'is_active' => true,
        'track_stock' => true,
        'selling_price' => '10000.00',
        'min_stock' => '10.0000',
    ]);
    StockBalance::create([
        'product_id' => $p1->id,
        'warehouse_id' => $wh->id,
        'quantity' => '9.0000',
        'avg_cost' => '10000.0000',
    ]);

    // P2: Boundary: min_stock 10.0000, current balance 10.0000 (Shortage = 0, NOT low stock!)
    $p2 = Product::create([
        'sku' => 'DASH-P2',
        'name' => 'Produk Dash 2',
        'category_id' => $cat->id,
        'base_unit_id' => $unit->id,
        'is_active' => true,
        'track_stock' => true,
        'selling_price' => '10000.00',
        'min_stock' => '10.0000',
    ]);
    StockBalance::create([
        'product_id' => $p2->id,
        'warehouse_id' => $wh->id,
        'quantity' => '10.0000',
        'avg_cost' => '10000.0000',
    ]);

    // P3: min_stock 15.0000, NO balance row in stock_balances (Counted as 0, Shortage = 15.0000)
    $p3 = Product::create([
        'sku' => 'DASH-P3',
        'name' => 'Produk Dash 3',
        'category_id' => $cat->id,
        'base_unit_id' => $unit->id,
        'is_active' => true,
        'track_stock' => true,
        'selling_price' => '10000.00',
        'min_stock' => '15.0000',
    ]);

    // P4: min_stock 0.0000, NO balance row (Ignored from low stock alerts because min_stock <= 0)
    Product::create([
        'sku' => 'DASH-P4',
        'name' => 'Produk Dash 4',
        'category_id' => $cat->id,
        'base_unit_id' => $unit->id,
        'is_active' => true,
        'track_stock' => true,
        'selling_price' => '10000.00',
        'min_stock' => '0.0000',
    ]);

    // 2. Fixture Purchase Orders
    PurchaseOrder::create([
        'number' => 'PO/DASH/001',
        'supplier_id' => $supp->id,
        'warehouse_id' => $wh->id,
        'order_date' => '2026-10-01',
        'status' => 'approved',
        'total' => '125000.50',
    ]);
    PurchaseOrder::create([
        'number' => 'PO/DASH/002',
        'supplier_id' => $supp->id,
        'warehouse_id' => $wh->id,
        'order_date' => '2026-10-02',
        'status' => 'partially_received',
        'total' => '75000.25',
    ]);
    PurchaseOrder::create([
        'number' => 'PO/DASH/003',
        'supplier_id' => $supp->id,
        'warehouse_id' => $wh->id,
        'order_date' => '2026-10-03',
        'status' => 'draft',
        'total' => '99999.00',
    ]);
    PurchaseOrder::create([
        'number' => 'PO/DASH/004',
        'supplier_id' => $supp->id,
        'warehouse_id' => $wh->id,
        'order_date' => '2026-10-04',
        'status' => 'void',
        'total' => '88888.00',
    ]);

    // 3. Fixture Goods Receipts
    $poRef = PurchaseOrder::where('number', 'PO/DASH/001')->first();
    GoodsReceipt::create([
        'number' => 'GR/DASH/001',
        'purchase_order_id' => $poRef->id,
        'supplier_id' => $supp->id,
        'warehouse_id' => $wh->id,
        'receipt_date' => '2026-10-05',
        'status' => 'draft',
    ]);
    GoodsReceipt::create([
        'number' => 'GR/DASH/002',
        'purchase_order_id' => $poRef->id,
        'supplier_id' => $supp->id,
        'warehouse_id' => $wh->id,
        'receipt_date' => '2026-10-06',
        'status' => 'draft',
    ]);
    GoodsReceipt::create([
        'number' => 'GR/DASH/003',
        'purchase_order_id' => $poRef->id,
        'supplier_id' => $supp->id,
        'warehouse_id' => $wh->id,
        'receipt_date' => '2026-10-07',
        'status' => 'posted',
    ]);

    // 4. Fixture Sales Orders
    SalesOrder::create([
        'number' => 'SO/DASH/001',
        'customer_id' => $cust->id,
        'warehouse_id' => $wh->id,
        'order_date' => '2026-10-01',
        'status' => 'approved',
        'total' => '150000.00',
    ]);
    SalesOrder::create([
        'number' => 'SO/DASH/002',
        'customer_id' => $cust->id,
        'warehouse_id' => $wh->id,
        'order_date' => '2026-10-02',
        'status' => 'partially_delivered',
        'total' => '50000.50',
    ]);
    SalesOrder::create([
        'number' => 'SO/DASH/003',
        'customer_id' => $cust->id,
        'warehouse_id' => $wh->id,
        'order_date' => '2026-10-03',
        'status' => 'draft',
        'total' => '90000.00',
    ]);
    SalesOrder::create([
        'number' => 'SO/DASH/004',
        'customer_id' => $cust->id,
        'warehouse_id' => $wh->id,
        'order_date' => '2026-10-04',
        'status' => 'void',
        'total' => '80000.00',
    ]);

    // 5. Fixture Delivery Orders
    $soRef = SalesOrder::where('number', 'SO/DASH/001')->first();
    DeliveryOrder::create([
        'number' => 'DO/DASH/001',
        'sales_order_id' => $soRef->id,
        'customer_id' => $cust->id,
        'warehouse_id' => $wh->id,
        'delivery_date' => '2026-10-05',
        'status' => 'draft',
    ]);
    DeliveryOrder::create([
        'number' => 'DO/DASH/002',
        'sales_order_id' => $soRef->id,
        'customer_id' => $cust->id,
        'warehouse_id' => $wh->id,
        'delivery_date' => '2026-10-06',
        'status' => 'draft',
    ]);
    DeliveryOrder::create([
        'number' => 'DO/DASH/003',
        'sales_order_id' => $soRef->id,
        'customer_id' => $cust->id,
        'warehouse_id' => $wh->id,
        'delivery_date' => '2026-10-07',
        'status' => 'posted',
    ]);

    // 6. Fixture Stock Movements (7 rows)
    for ($m = 1; $m <= 7; $m++) {
        StockMovement::create([
            'movement_date' => now()->subMinutes(10 - $m),
            'product_id' => $p1->id,
            'warehouse_id' => $wh->id,
            'movement_type' => 'receipt',
            'quantity' => '1.0000',
            'unit_cost' => '10000.0000',
            'balance_after' => (string) $m.'.0000',
            'avg_cost_after' => '10000.0000',
            'source_type' => 'App\\Models\\GoodsReceipt',
            'source_id' => 1,
        ]);
    }

    DB::enableQueryLog();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            // PO: 2 active (approved + partially_received), total 125000.50 + 75000.25 = 200000.75
            ->where('purchaseOrders.active_po_count', 2)
            ->where('purchaseOrders.active_po_total', '200000.75')
            // GR: 2 draft
            ->where('goodsReceipts.draft_gr_count', 2)
            // SO: 2 active (approved + partially_delivered), total 150000.00 + 50000.50 = 200000.50
            ->where('salesOrders.active_so_count', 2)
            ->where('salesOrders.active_so_total', '200000.50')
            // DO: 2 draft
            ->where('deliveryOrders.draft_do_count', 2)
            // Stock Balances: 2 products low stock (P3 with shortage 15, P1 with shortage 1)
            ->where('stockBalances.low_stock_count', 2)
            ->has('stockBalances.deficient_products', 2)
            ->where('stockBalances.deficient_products.0.sku', 'DASH-P3')
            ->where('stockBalances.deficient_products.0.shortage', '15.0000')
            ->where('stockBalances.deficient_products.1.sku', 'DASH-P1')
            ->where('stockBalances.deficient_products.1.shortage', '1.0000')
            // Stock Movements: limited to exactly 5 rows
            ->has('stockMovements', 5)
        );

    // Verify bounded queries (no N+1): at most 12 queries total during full HTTP request
    $queryLog = DB::getQueryLog();
    expect(count($queryLog))->toBeLessThanOrEqual(12);

    // Verify DashboardSummary alone runs in at most 8 bounded queries without N+1
    DB::flushQueryLog();
    $summary = app(DashboardSummary::class);
    $summary->getPurchaseOrdersSummary();
    $summary->getGoodsReceiptsSummary();
    $summary->getSalesOrdersSummary();
    $summary->getDeliveryOrdersSummary();
    $summary->getStockBalancesSummary();
    $summary->getRecentStockMovements();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(8);
});
