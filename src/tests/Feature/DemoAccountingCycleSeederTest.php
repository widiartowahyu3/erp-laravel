<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Reports\BalanceSheetReport;
use App\Services\Reports\InventoryReconciliationReport;
use App\Services\Reports\PayableAgingReport;
use App\Services\Reports\ReceivableAgingReport;
use App\Services\Reports\ReportFilter;
use App\Services\Reports\TrialBalanceReport;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\DemoAccountingCycleSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    Carbon::setTestNow('2026-11-05 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function demoBalance(string $code): string
{
    $row = collect((new TrialBalanceReport)->handle(new ReportFilter(dateFrom: '2025-01-01', dateTo: '2026-11-05'))->rows)->firstWhere('code', $code);

    return $row === null ? '0.00' : $row['closing'];
}

/**
 * @return array<string, mixed>
 */
function demoFingerprint(): array
{
    return [
        'purchase_orders' => (string) DB::table('purchase_orders')->sum('total'),
        'sales_orders' => (string) DB::table('sales_orders')->sum('total'),
        'purchase_invoices' => (string) DB::table('purchase_invoices')->sum('total'),
        'sales_invoices' => (string) DB::table('sales_invoices')->sum('total'),
        'payments' => (string) DB::table('payments')->sum('amount'),
        'journal_lines' => DB::table('journal_lines')->count(),
        'stock' => (string) DB::table('stock_balances')->sum('quantity'),
    ];
}

test('the demo cycle leaves the books balanced, reconciled and with nothing unbilled, even when run twice', function (): void {
    $seeder = new DemoAccountingCycleSeeder;

    $seeder->run();
    $first = demoFingerprint();
    $seeder->run();

    expect(demoFingerprint())->toBe($first);

    $sheet = (new BalanceSheetReport)->handle(new ReportFilter(asOf: '2026-11-05'));
    $reconciliation = (new InventoryReconciliationReport)->handle(new ReportFilter);
    $trial = (new TrialBalanceReport)->handle(new ReportFilter(dateFrom: '2025-01-01', dateTo: '2026-11-05'));

    expect($sheet->summary['balanced'])->toBeTrue()
        ->and($trial->summary['balanced'])->toBeTrue()
        ->and($reconciliation->summary['difference'])->toBe('0.00')
        ->and(demoBalance('2103'))->toBe('0.00');
});

test('the demo cycle holds the promised documents and fills the reports', function (): void {
    (new DemoAccountingCycleSeeder)->run();

    expect(DB::table('purchase_orders')->count())->toBe(20)
        ->and(DB::table('goods_receipts')->where('status', 'posted')->count())->toBe(24)
        ->and(DB::table('purchase_invoices')->where('status', '!=', 'void')->count())->toBe(20)
        ->and(DB::table('sales_orders')->count())->toBe(30)
        ->and(DB::table('delivery_orders')->where('status', 'posted')->count())->toBe(30)
        ->and(DB::table('sales_invoices')->where('status', '!=', 'void')->count())->toBe(30)
        ->and(DB::table('sales_invoices')->where('status', 'void')->count())->toBe(1)
        ->and(DB::table('payments')->where('status', 'void')->count())->toBe(1)
        ->and(DB::table('stock_adjustments')->where('status', 'posted')->count())->toBe(5)
        ->and(DB::table('journals')->whereNull('source_type')->where('status', 'posted')->count())->toBe(5)
        ->and(DB::table('journals')->where('status', 'draft')->count())->toBe(0);

    $receivable = (new ReceivableAgingReport)->handle(new ReportFilter);
    $payable = (new PayableAgingReport)->handle(new ReportFilter);

    expect($receivable->summary['total'])->toBe(demoBalance('1201'))
        ->and($payable->summary['total'])->toBe(demoBalance('2101'))
        ->and($receivable->summary['days_over_90'])->not->toBe('0.00')
        ->and($receivable->summary['not_due'])->not->toBe('0.00')
        ->and($payable->summary['days_over_90'])->not->toBe('0.00');
});

test('the same seed gives the same figures on a clean database', function (): void {
    DB::beginTransaction();
    (new DemoAccountingCycleSeeder)->run();
    $first = demoFingerprint();
    DB::rollBack();

    (new DemoAccountingCycleSeeder)->run();

    expect(demoFingerprint())->toBe($first);
});

test('the demo cycle refuses to run in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    try {
        (new DemoAccountingCycleSeeder)->run();
        $this->fail('Expected the production guard to stop the seeder.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('production');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect(DB::table('purchase_orders')->count())->toBe(0);
});

test('the demo cycle writes only through services and stays out of the default seeder', function (): void {
    $source = (string) file_get_contents(database_path('seeders/DemoAccountingCycleSeeder.php'));

    expect($source)->not->toContain('DB::')
        ->and($source)->not->toContain('->insert(')
        ->and($source)->not->toContain('::create(')
        ->and((string) file_get_contents(database_path('seeders/DatabaseSeeder.php')))->not->toContain('DemoAccountingCycleSeeder');
});
