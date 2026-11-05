<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Accounting\PaymentService;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Services\Reports\BalanceSheetReport;
use App\Services\Reports\IncomeStatementReport;
use App\Services\Reports\InventoryReconciliationReport;
use App\Services\Reports\PayableAgingReport;
use App\Services\Reports\ReceivableAgingReport;
use App\Services\Reports\ReportFilter;
use App\Services\Reports\TrialBalanceReport;
use App\Services\Sales\DeliveryOrderService;
use App\Services\Sales\SalesInvoiceService;
use App\Services\Sales\SalesOrderService;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchaseInvoiceFixtures;
use Tests\Support\ReportFixtures;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
    Carbon::setTestNow('2026-11-30 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function endToEndBalance(string $code): string
{
    $row = collect((new TrialBalanceReport)->handle(new ReportFilter(dateFrom: '2026-01-01', dateTo: '2026-12-31'))->rows)->firstWhere('code', $code);

    return $row === null ? '0.00' : $row['closing'];
}

/**
 * The checks that must hold after every stage.
 *
 * @param  array{receivable: string, payable: string, stock: string, grir: string, net_profit: string}  $expected
 */
function assertBooksHold(array $expected): void
{
    // (a) Debit equals credit across all posted journal lines.
    $totals = DB::table('journal_lines as l')
        ->join('journals as j', 'j.id', '=', 'l.journal_id')
        ->where('j.status', 'posted')
        ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
        ->first();
    expect((string) $totals->debit)->toBe((string) $totals->credit);

    // (b) The accounting equation holds.
    $sheet = (new BalanceSheetReport)->handle(new ReportFilter(asOf: '2026-11-30'));
    expect($sheet->summary['balanced'])->toBeTrue()
        ->and($sheet->summary['assets'])->toBe($sheet->summary['liabilities_and_equity']);

    // (c) Goods received not invoiced, zero once every receipt is billed.
    expect(endToEndBalance('2103'))->toBe($expected['grir']);

    // (d) Stock value matches the inventory account.
    $reconciliation = (new InventoryReconciliationReport)->handle(new ReportFilter);
    expect($reconciliation->summary['difference'])->toBe('0.00')
        ->and($reconciliation->summary['status'])->toBe('matched')
        ->and($reconciliation->summary['stock_value'])->toBe($expected['stock']);

    // (e) Aging buckets add up to what is outstanding on the invoices and on the control account.
    $receivable = (new ReceivableAgingReport)->handle(new ReportFilter);
    $payable = (new PayableAgingReport)->handle(new ReportFilter);
    $outstanding = (string) DB::table('sales_invoices')->whereIn('status', ['posted', 'partially_paid'])->selectRaw('COALESCE(SUM(total - paid_amount), 0) as left_over')->value('left_over');
    $bucketSum = bcadd(bcadd(bcadd(bcadd($receivable->summary['not_due'], $receivable->summary['days_1_30'], 2), $receivable->summary['days_31_60'], 2), $receivable->summary['days_61_90'], 2), $receivable->summary['days_over_90'], 2);

    expect($bucketSum)->toBe($outstanding)
        ->and($receivable->summary['total'])->toBe($expected['receivable'])
        ->and(endToEndBalance('1201'))->toBe($expected['receivable'])
        ->and($payable->summary['total'])->toBe($expected['payable'])
        ->and(endToEndBalance('2101'))->toBe($expected['payable']);

    // (f) Net profit equals the figure worked out by hand in the test.
    expect((new IncomeStatementReport)->handle(new ReportFilter(dateFrom: '2026-11-01', dateTo: '2026-11-30'))->summary['net_profit'])->toBe($expected['net_profit']);

    // (g) No automatic journal is left in draft, and each posted document has its posted journal.
    expect(DB::table('journals')->where('status', 'draft')->whereNotNull('source_type')->count())->toBe(0);

    foreach ([
        ['goods_receipts', StockService::SOURCE_GOODS_RECEIPT, ['posted']],
        ['delivery_orders', StockService::SOURCE_DELIVERY_ORDER, ['posted']],
        ['stock_adjustments', StockService::SOURCE_STOCK_ADJUSTMENT, ['posted']],
        ['sales_invoices', SalesInvoice::JOURNAL_SOURCE, ['posted', 'partially_paid', 'paid']],
        ['purchase_invoices', PurchaseInvoice::JOURNAL_SOURCE, ['posted', 'partially_paid', 'paid']],
        ['payments', Payment::JOURNAL_SOURCE, ['posted']],
    ] as [$table, $source, $statuses]) {
        $documents = DB::table($table)->whereIn('status', $statuses)->pluck('id');

        foreach ($documents as $id) {
            expect(DB::table('journals')->where('source_type', $source)->where('source_id', $id)->whereNull('reversal_of_id')->where('status', 'posted')->count())
                ->toBe(1, "{$table} {$id} needs its posted journal.");
        }
    }
}

test('a full trading cycle keeps the books balanced at every stage', function (): void {
    $payments = new PaymentService;
    $cash = ReportFixtures::accountId('1101');
    $bank = ReportFixtures::accountId('1102');

    // Owner capital, as one manual journal: bank +2000.00.
    ReportFixtures::journal('2026-11-01', [['1102', '2000.00', '0.00'], ['3101', '0.00', '2000.00']]);
    assertBooksHold(['receivable' => '0.00', 'payable' => '0.00', 'stock' => '0.00', 'grir' => '0.00', 'net_profit' => '0.00']);

    // PO of 10 units at 100.00, received in two parts.
    $purchaseOrder = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '10.0000', 'unit_price' => '100.00']]);
    $firstReceipt = PurchaseInvoiceFixtures::postedReceipt($purchaseOrder, ['6.0000']);
    assertBooksHold(['receivable' => '0.00', 'payable' => '0.00', 'stock' => '600.00', 'grir' => '600.00', 'net_profit' => '0.00']);

    $secondReceipt = PurchaseInvoiceFixtures::postedReceipt($purchaseOrder, ['4.0000']);
    assertBooksHold(['receivable' => '0.00', 'payable' => '0.00', 'stock' => '1000.00', 'grir' => '1000.00', 'net_profit' => '0.00']);

    // Both receipts billed, which empties the GR/IR account.
    $purchaseInvoice = (new PurchaseInvoiceService)->post(PurchaseInvoiceFixtures::draftInvoice($purchaseOrder, [$firstReceipt, $secondReceipt]));
    expect($purchaseInvoice->total)->toBe('1000.00');
    assertBooksHold(['receivable' => '0.00', 'payable' => '1000.00', 'stock' => '1000.00', 'grir' => '0.00', 'net_profit' => '0.00']);

    // Paid part by part.
    $disbursement = fn (string $amount, string $date) => $payments->post($payments->createDisbursement([
        'supplier_id' => $purchaseOrder->supplier_id,
        'payment_date' => $date,
        'method' => 'bank_transfer',
        'account_id' => $bank,
        'amount' => $amount,
        'allocations' => [['purchase_invoice_id' => $purchaseInvoice->id, 'amount' => $amount]],
    ]));
    $disbursement('400.00', '2026-11-05');
    expect($purchaseInvoice->fresh()->status->value)->toBe('partially_paid');
    assertBooksHold(['receivable' => '0.00', 'payable' => '600.00', 'stock' => '1000.00', 'grir' => '0.00', 'net_profit' => '0.00']);

    $disbursement('600.00', '2026-11-06');
    expect($purchaseInvoice->fresh()->status->value)->toBe('paid');
    assertBooksHold(['receivable' => '0.00', 'payable' => '0.00', 'stock' => '1000.00', 'grir' => '0.00', 'net_profit' => '0.00']);

    // SO of 5 units at 300.00 with 100.00 discount, cost 500.00 on delivery.
    $productId = $purchaseOrder->items[0]->product_id;
    $customerId = TestDataFactory::createCustomer(['credit_limit' => 0]);
    $orders = new SalesOrderService;
    $deliveries = new DeliveryOrderService(new StockService);
    $invoices = new SalesInvoiceService;

    $sell = function (string $quantity, string $price, string $discount) use ($orders, $deliveries, $customerId, $productId, $purchaseOrder): array {
        $order = $orders->create([
            'customer_id' => $customerId,
            'warehouse_id' => $purchaseOrder->warehouse_id,
            'order_date' => '2026-11-10',
            'items' => [['product_id' => $productId, 'quantity' => $quantity, 'unit_price' => $price, 'discount_amount' => $discount]],
        ]);
        $orders->approve($order);
        $order = $order->fresh(['items']);

        $delivery = $deliveries->post($deliveries->create([
            'sales_order_id' => $order->id,
            'customer_id' => $customerId,
            'warehouse_id' => $purchaseOrder->warehouse_id,
            'delivery_date' => '2026-11-11',
            'items' => [['sales_order_item_id' => $order->items[0]->id, 'product_id' => $productId, 'quantity' => $quantity]],
        ]));

        return [$order, $delivery];
    };
    $bill = fn ($order, $delivery) => $invoices->post($invoices->create([
        'sales_order_id' => $order->id,
        'invoice_date' => '2026-11-12',
        'items' => [['delivery_order_item_id' => $delivery->items[0]->id, 'quantity' => $delivery->items[0]->quantity]],
    ]));

    [$order, $delivery] = $sell('5.0000', '300.00', '100.00');
    // Cost is recognised at delivery, before any revenue: profit -500.00.
    assertBooksHold(['receivable' => '0.00', 'payable' => '0.00', 'stock' => '500.00', 'grir' => '0.00', 'net_profit' => '-500.00']);

    // Revenue 1500.00 - discount 100.00 - cost 500.00.
    $invoice = $bill($order, $delivery);
    expect($invoice->total)->toBe('1400.00');
    assertBooksHold(['receivable' => '1400.00', 'payable' => '0.00', 'stock' => '500.00', 'grir' => '0.00', 'net_profit' => '900.00']);

    $receipt = fn (string $amount, string $date, int $invoiceId) => $payments->post($payments->createReceipt([
        'customer_id' => $customerId,
        'payment_date' => $date,
        'method' => 'cash',
        'account_id' => $cash,
        'amount' => $amount,
        'allocations' => [['sales_invoice_id' => $invoiceId, 'amount' => $amount]],
    ]));
    $receipt('500.00', '2026-11-13', $invoice->id);
    expect($invoice->fresh()->status->value)->toBe('partially_paid');
    assertBooksHold(['receivable' => '900.00', 'payable' => '0.00', 'stock' => '500.00', 'grir' => '0.00', 'net_profit' => '900.00']);

    $receipt('900.00', '2026-11-14', $invoice->id);
    expect($invoice->fresh()->status->value)->toBe('paid');
    assertBooksHold(['receivable' => '0.00', 'payable' => '0.00', 'stock' => '500.00', 'grir' => '0.00', 'net_profit' => '900.00']);

    // Stock count finds one unit short of the 5 left: 100.00 to adjustment expense.
    $adjustments = new StockAdjustmentService(new StockService);
    $adjustments->post($adjustments->create([
        'warehouse_id' => $purchaseOrder->warehouse_id,
        'adjustment_date' => '2026-11-15',
        'reason' => 'Stock opname',
        'items' => [['product_id' => $productId, 'counted_quantity' => '4.0000']],
    ]));
    assertBooksHold(['receivable' => '0.00', 'payable' => '0.00', 'stock' => '400.00', 'grir' => '0.00', 'net_profit' => '800.00']);

    // One unit sold at 300.00 (cost 100.00), its invoice voided by a reversal, then billed again.
    [$secondOrder, $secondDelivery] = $sell('1.0000', '300.00', '0.00');
    assertBooksHold(['receivable' => '0.00', 'payable' => '0.00', 'stock' => '300.00', 'grir' => '0.00', 'net_profit' => '700.00']);

    $wrong = $bill($secondOrder, $secondDelivery);
    assertBooksHold(['receivable' => '300.00', 'payable' => '0.00', 'stock' => '300.00', 'grir' => '0.00', 'net_profit' => '1000.00']);

    $invoices->void($wrong, 'Salah tanggal');
    $reversal = DB::table('journals')->where('source_type', SalesInvoice::JOURNAL_SOURCE)->where('source_id', $wrong->id)->whereNotNull('reversal_of_id')->first();
    expect($reversal)->not->toBeNull()->and($reversal->status)->toBe('posted');
    assertBooksHold(['receivable' => '0.00', 'payable' => '0.00', 'stock' => '300.00', 'grir' => '0.00', 'net_profit' => '700.00']);

    $bill($secondOrder, $secondDelivery);
    assertBooksHold(['receivable' => '300.00', 'payable' => '0.00', 'stock' => '300.00', 'grir' => '0.00', 'net_profit' => '1000.00']);

    // One manual expense journal: 50.00 from cash.
    ReportFixtures::journal('2026-11-20', [['6101', '50.00', '0.00'], ['1101', '0.00', '50.00']]);
    assertBooksHold(['receivable' => '300.00', 'payable' => '0.00', 'stock' => '300.00', 'grir' => '0.00', 'net_profit' => '950.00']);

    // Final position worked out by hand:
    // revenue 1800.00 - discount 100.00 = 1700.00; cost 600.00 + adjustment 100.00 = 700.00; expenses 50.00.
    $income = (new IncomeStatementReport)->handle(new ReportFilter(dateFrom: '2026-11-01', dateTo: '2026-11-30'))->summary;

    expect($income)->toBe([
        'revenue' => '1700.00',
        'cost_of_sales' => '700.00',
        'gross_profit' => '1000.00',
        'expenses' => '50.00',
        'net_profit' => '950.00',
    ]);

    // Cash: bank 2000 - 400 - 600 = 1000; cash 500 + 900 - 50 = 1350.
    $sheet = (new BalanceSheetReport)->handle(new ReportFilter(asOf: '2026-11-30'));

    expect(endToEndBalance('1102'))->toBe('1000.00')
        ->and(endToEndBalance('1101'))->toBe('1350.00')
        ->and($sheet->summary)->toBe([
            'as_of' => '2026-11-30',
            'assets' => '2950.00',
            'liabilities' => '0.00',
            'equity' => '2950.00',
            'liabilities_and_equity' => '2950.00',
            'balanced' => true,
        ]);
});
