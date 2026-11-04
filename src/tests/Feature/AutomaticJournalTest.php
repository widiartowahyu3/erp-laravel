<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\JournalStatus;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\DeliveryOrder;
use App\Models\GoodsReceipt;
use App\Models\Journal;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Sales\DeliveryOrderService;
use App\Services\Sales\SalesOrderService;
use App\Support\Decimal;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
});

/**
 * @param  list<array{product: int, quantity: string, cost: string}>  $lines
 */
function draftReceipt(array $lines, ?int $warehouseId = null): GoodsReceipt
{
    $supplierId = TestDataFactory::createSupplier();
    $warehouseId ??= TestDataFactory::createWarehouse();
    $poService = new PurchaseOrderService;

    $po = $poService->create([
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-20',
        'items' => array_map(fn (array $l): array => [
            'product_id' => $l['product'], 'quantity' => $l['quantity'], 'unit_price' => $l['cost'],
        ], $lines),
    ]);
    $poService->approve($po);

    return (new GoodsReceiptService(new StockService))->create([
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'receipt_date' => '2026-10-25',
        'items' => $po->items()->get()->map(fn ($item): array => [
            'purchase_order_item_id' => $item->id, 'product_id' => $item->product_id, 'quantity' => $item->quantity,
        ])->all(),
    ]);
}

function seedStock(int $productId, int $warehouseId, string $quantity, string $cost): void
{
    DB::transaction(fn () => (new StockService)->increase(
        $productId, $warehouseId, $quantity, $cost,
        StockMovementType::ADJUSTMENT_IN, StockService::SOURCE_STOCK_ADJUSTMENT, 1
    ));
}

function draftDelivery(int $productId, int $warehouseId, string $quantity, string $price): DeliveryOrder
{
    $customerId = TestDataFactory::createCustomer();
    $soService = new SalesOrderService;

    $so = $soService->create([
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'order_date' => '2026-10-20',
        'items' => [['product_id' => $productId, 'quantity' => $quantity, 'unit_price' => $price]],
    ]);
    $soService->approve($so);
    $soItem = $so->items()->firstOrFail();

    return (new DeliveryOrderService(new StockService))->create([
        'sales_order_id' => $so->id,
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'delivery_date' => '2026-10-26',
        'items' => [['sales_order_item_id' => $soItem->id, 'product_id' => $productId, 'quantity' => $quantity]],
    ]);
}

/**
 * Net debit minus credit per account code, across every posted journal of a source.
 *
 * @return array<string, string>
 */
function netBySource(string $sourceType, int $sourceId): array
{
    $rows = DB::table('journal_lines as l')
        ->join('journals as j', 'j.id', '=', 'l.journal_id')
        ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
        ->where('j.source_type', $sourceType)->where('j.source_id', $sourceId)->where('j.status', 'posted')
        ->select('a.code', 'l.debit', 'l.credit')->get();

    $net = [];
    foreach ($rows as $row) {
        $net[$row->code] = Decimal::sub(Decimal::add($net[$row->code] ?? '0', $row->debit), $row->credit);
    }

    ksort($net);

    return $net;
}

/**
 * @return list<array{string, string, string}>
 */
function journalLineValues(Journal $journal): array
{
    return $journal->lines()->with('account')->get()->map(
        fn ($l): array => [$l->account->code, $l->debit, $l->credit]
    )->all();
}

test('posting a goods receipt journals inventory against grir at exact values', function (): void {
    $gr = draftReceipt([
        ['product' => TestDataFactory::createProduct(), 'quantity' => '10.0000', 'cost' => '950.0000'],
        ['product' => TestDataFactory::createProduct(), 'quantity' => '4.0000', 'cost' => '250.0000'],
    ]);

    (new GoodsReceiptService(new StockService))->post($gr);

    $journal = Journal::where('source_type', 'goods_receipt')->where('source_id', $gr->id)->firstOrFail();

    expect($journal->status)->toBe(JournalStatus::POSTED)
        ->and($journal->journal_date->toDateString())->toBe('2026-10-25')
        ->and($journal->description)->toContain($gr->number)
        ->and($journal->total_debit)->toBe('10500.00')
        ->and(journalLineValues($journal))->toBe([
            ['1301', '10500.00', '0.00'],
            ['2103', '0.00', '10500.00'],
        ]);
});

test('voiding a posted goods receipt reverses its journal and neutralizes the accounts', function (): void {
    $service = new GoodsReceiptService(new StockService);
    $gr = $service->post(draftReceipt([
        ['product' => TestDataFactory::createProduct(), 'quantity' => '10.0000', 'cost' => '950.0000'],
    ]));

    $service->void($gr, 'Barang salah');

    $original = Journal::where('source_id', $gr->id)->where('source_type', 'goods_receipt')->whereNull('reversal_of_id')->firstOrFail();
    $reversal = Journal::where('reversal_of_id', $original->id)->firstOrFail();

    expect($original->status)->toBe(JournalStatus::POSTED)
        ->and($reversal->source_type)->toBe('goods_receipt')
        ->and(journalLineValues($reversal))->toBe([
            ['1301', '0.00', '9500.00'],
            ['2103', '9500.00', '0.00'],
        ])
        ->and(netBySource('goods_receipt', $gr->id))->toBe(['1301' => '0.00', '2103' => '0.00']);
});

test('voiding a goods receipt keeps the stock value equal to the inventory account', function (): void {
    $service = new GoodsReceiptService(new StockService);
    $productId = TestDataFactory::createProduct();
    $warehouseId = TestDataFactory::createWarehouse();

    $first = $service->post(draftReceipt([['product' => $productId, 'quantity' => '10.0000', 'cost' => '100.0000']], $warehouseId));
    $second = $service->post(draftReceipt([['product' => $productId, 'quantity' => '10.0000', 'cost' => '200.0000']], $warehouseId));
    $service->void($second, 'Harga salah');

    $balance = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->firstOrFail();
    $accountValue = Decimal::add(
        netBySource('goods_receipt', $first->id)['1301'],
        netBySource('goods_receipt', $second->id)['1301']
    );

    expect(Decimal::mul($balance->quantity, $balance->avg_cost))->toBe($accountValue)
        ->and($accountValue)->toBe('1000.00');
});

test('voiding a draft goods receipt creates no journal', function (): void {
    $service = new GoodsReceiptService(new StockService);
    $gr = draftReceipt([['product' => TestDataFactory::createProduct(), 'quantity' => '1.0000', 'cost' => '10.0000']]);

    $service->void($gr, 'Tidak jadi');

    expect(Journal::where('source_id', $gr->id)->where('source_type', 'goods_receipt')->count())->toBe(0);
});

test('a zero value goods receipt posts without a journal', function (): void {
    $gr = (new GoodsReceiptService(new StockService))->post(
        draftReceipt([['product' => TestDataFactory::createProduct(), 'quantity' => '5.0000', 'cost' => '0']])
    );

    expect($gr->status)->toBe(GoodsReceiptStatus::POSTED)
        ->and(Journal::where('source_id', $gr->id)->where('source_type', 'goods_receipt')->count())->toBe(0);
});

test('journal failure rolls back the whole goods receipt posting; active account control succeeds', function (): void {
    $productId = TestDataFactory::createProduct();
    $warehouseId = TestDataFactory::createWarehouse();
    $gr = draftReceipt([['product' => $productId, 'quantity' => '10.0000', 'cost' => '950.0000']], $warehouseId);
    $journalsBefore = Journal::count();

    DB::table('chart_of_accounts')->where('code', '2103')->update(['is_active' => false]);

    expect(fn () => (new GoodsReceiptService(new StockService))->post($gr))->toThrow(BusinessRuleException::class, '2103');

    expect($gr->fresh()->status)->toBe(GoodsReceiptStatus::DRAFT)
        ->and(StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->exists())->toBeFalse()
        ->and(DB::table('purchase_order_items')->where('purchase_order_id', $gr->purchase_order_id)->value('received_quantity'))->toBe('0.0000')
        ->and(Journal::count())->toBe($journalsBefore);

    DB::table('chart_of_accounts')->where('code', '2103')->update(['is_active' => true]);

    expect((new GoodsReceiptService(new StockService))->post($gr)->status)->toBe(GoodsReceiptStatus::POSTED);
});

test('posting a goods receipt twice does not duplicate its journal', function (): void {
    $service = new GoodsReceiptService(new StockService);
    $gr = $service->post(draftReceipt([['product' => TestDataFactory::createProduct(), 'quantity' => '1.0000', 'cost' => '10.0000']]));

    expect(fn () => $service->post($gr))->toThrow(InvalidStateTransitionException::class);
    expect(Journal::where('source_id', $gr->id)->where('source_type', 'goods_receipt')->count())->toBe(1);
});

test('the journal is written after the source document and stock balance are locked', function (): void {
    $gr = draftReceipt([['product' => TestDataFactory::createProduct(), 'quantity' => '1.0000', 'cost' => '10.0000']]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    (new GoodsReceiptService(new StockService))->post($gr);

    $position = function (string $table, string $needle) use ($queries): int {
        foreach ($queries as $index => $sql) {
            if (str_contains($sql, $table) && str_contains($sql, $needle)) {
                return $index;
            }
        }

        return -1;
    };

    $documentLock = $position('`goods_receipts`', 'for update');
    $balanceLock = $position('`stock_balances`', 'for update');
    $journalInsert = $position('insert into `journals`', 'insert');

    expect($documentLock)->toBeGreaterThanOrEqual(0)
        ->and($balanceLock)->toBeGreaterThan($documentLock)
        ->and($journalInsert)->toBeGreaterThan($balanceLock);
});

test('posting a delivery order journals cost of goods sold at the snapshot cost, not the selling price', function (): void {
    $productId = TestDataFactory::createProduct();
    $warehouseId = TestDataFactory::createWarehouse();
    seedStock($productId, $warehouseId, '10.0000', '950.0000');

    $do = draftDelivery($productId, $warehouseId, '3.0000', '2000.0000');
    (new DeliveryOrderService(new StockService))->post($do);

    $journal = Journal::where('source_type', 'delivery_order')->where('source_id', $do->id)->firstOrFail();

    expect($journal->journal_date->toDateString())->toBe('2026-10-26')
        ->and($journal->description)->toContain($do->number)
        ->and(journalLineValues($journal))->toBe([
            ['5101', '2850.00', '0.00'],
            ['1301', '0.00', '2850.00'],
        ]);
});

test('voiding a posted delivery order reverses its journal', function (): void {
    $productId = TestDataFactory::createProduct();
    $warehouseId = TestDataFactory::createWarehouse();
    seedStock($productId, $warehouseId, '10.0000', '950.0000');

    $service = new DeliveryOrderService(new StockService);
    $do = $service->post(draftDelivery($productId, $warehouseId, '3.0000', '2000.0000'));
    $service->void($do, 'Pelanggan menolak');

    expect(netBySource('delivery_order', $do->id))->toBe(['1301' => '0.00', '5101' => '0.00'])
        ->and(Journal::where('source_type', 'delivery_order')->where('source_id', $do->id)->count())->toBe(2);
});

test('journal failure rolls back the whole delivery order posting; active account control succeeds', function (): void {
    $productId = TestDataFactory::createProduct();
    $warehouseId = TestDataFactory::createWarehouse();
    seedStock($productId, $warehouseId, '10.0000', '950.0000');
    $do = draftDelivery($productId, $warehouseId, '3.0000', '2000.0000');
    $journalsBefore = Journal::count();

    DB::table('chart_of_accounts')->where('code', '5101')->update(['is_active' => false]);

    expect(fn () => (new DeliveryOrderService(new StockService))->post($do))->toThrow(BusinessRuleException::class, '5101');

    expect($do->fresh()->status)->toBe(DeliveryOrderStatus::DRAFT)
        ->and(StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity'))->toBe('10.0000')
        ->and(DB::table('sales_order_items')->where('sales_order_id', $do->sales_order_id)->value('delivered_quantity'))->toBe('0.0000')
        ->and(Journal::count())->toBe($journalsBefore);

    DB::table('chart_of_accounts')->where('code', '5101')->update(['is_active' => true]);

    expect((new DeliveryOrderService(new StockService))->post($do)->status)->toBe(DeliveryOrderStatus::POSTED);
});

test('posting a stock adjustment with surplus and shortage in one document creates four lines', function (): void {
    $warehouseId = TestDataFactory::createWarehouse();
    $surplusProduct = TestDataFactory::createProduct();
    $shortageProduct = TestDataFactory::createProduct();
    seedStock($surplusProduct, $warehouseId, '10.0000', '100.0000');
    seedStock($shortageProduct, $warehouseId, '10.0000', '200.0000');

    $service = new StockAdjustmentService(new StockService);
    $adjustment = $service->post($service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-27',
        'reason' => 'Stock opname',
        'items' => [
            ['product_id' => $surplusProduct, 'counted_quantity' => '12.0000', 'unit_cost' => '150.0000'],
            ['product_id' => $shortageProduct, 'counted_quantity' => '7.0000'],
        ],
    ]));

    $journal = Journal::where('source_type', 'stock_adjustment')->where('source_id', $adjustment->id)->firstOrFail();

    expect($journal->journal_date->toDateString())->toBe('2026-10-27')
        ->and($journal->total_debit)->toBe('900.00')
        ->and(journalLineValues($journal))->toBe([
            ['1301', '300.00', '0.00'],
            ['5201', '0.00', '300.00'],
            ['5201', '600.00', '0.00'],
            ['1301', '0.00', '600.00'],
        ]);
});

test('voiding a posted stock adjustment reverses its journal', function (): void {
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();
    seedStock($productId, $warehouseId, '10.0000', '200.0000');

    $service = new StockAdjustmentService(new StockService);
    $adjustment = $service->post($service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-27',
        'reason' => 'Stock opname',
        'items' => [['product_id' => $productId, 'counted_quantity' => '7.0000']],
    ]));

    $service->void($adjustment, 'Hitungan ulang');

    expect(netBySource('stock_adjustment', $adjustment->id))->toBe(['1301' => '0.00', '5201' => '0.00'])
        ->and(Journal::where('source_type', 'stock_adjustment')->where('source_id', $adjustment->id)->count())->toBe(2);
});

test('a stock adjustment with no difference posts without a journal', function (): void {
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();
    seedStock($productId, $warehouseId, '10.0000', '200.0000');

    $service = new StockAdjustmentService(new StockService);
    $adjustment = $service->post($service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-27',
        'reason' => 'Stock opname',
        'items' => [['product_id' => $productId, 'counted_quantity' => '10.0000']],
    ]));

    expect($adjustment->status)->toBe(StockAdjustmentStatus::POSTED)
        ->and(Journal::where('source_type', 'stock_adjustment')->where('source_id', $adjustment->id)->count())->toBe(0);
});

test('journal failure rolls back the whole stock adjustment posting', function (): void {
    $warehouseId = TestDataFactory::createWarehouse();
    $productId = TestDataFactory::createProduct();
    seedStock($productId, $warehouseId, '10.0000', '200.0000');

    $service = new StockAdjustmentService(new StockService);
    $adjustment = $service->create([
        'warehouse_id' => $warehouseId,
        'adjustment_date' => '2026-10-27',
        'reason' => 'Stock opname',
        'items' => [['product_id' => $productId, 'counted_quantity' => '7.0000']],
    ]);

    DB::table('chart_of_accounts')->where('code', '5201')->update(['is_active' => false]);

    expect(fn () => (new StockAdjustmentService(new StockService))->post($adjustment))->toThrow(BusinessRuleException::class, '5201');

    expect(StockAdjustment::find($adjustment->id)->status)->toBe(StockAdjustmentStatus::DRAFT)
        ->and(StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity'))->toBe('10.0000');
});
