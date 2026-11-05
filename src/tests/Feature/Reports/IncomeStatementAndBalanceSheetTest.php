<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Services\Reports\BalanceSheetReport;
use App\Services\Reports\IncomeStatementReport;
use App\Services\Reports\ReportFilter;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportFixtures;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());

    // Capital 10000 in 2025, then one trading period.
    ReportFixtures::journal('2025-01-10', [['1101', '10000.00', '0.00'], ['3101', '0.00', '10000.00']]);
    // 2025 result: sales 1000 less cost 400, kept as cumulative profit.
    ReportFixtures::journal('2025-06-10', [['1201', '1000.00', '0.00'], ['4101', '0.00', '1000.00']]);
    ReportFixtures::journal('2025-06-10', [['5101', '400.00', '0.00'], ['1101', '0.00', '400.00']]);
    // October 2026: sales 5000 with discount 300, cost 2000, stock difference 50, overhead 700.
    ReportFixtures::journal('2026-10-05', [['1201', '4700.00', '0.00'], ['4201', '300.00', '0.00'], ['4101', '0.00', '5000.00']]);
    ReportFixtures::journal('2026-10-06', [['5101', '2000.00', '0.00'], ['1101', '0.00', '2000.00']]);
    ReportFixtures::journal('2026-10-07', [['5201', '50.00', '0.00'], ['1101', '0.00', '50.00']]);
    ReportFixtures::journal('2026-10-08', [['6101', '700.00', '0.00'], ['1101', '0.00', '700.00']]);
});

function incomeStatement(string $from, string $to): array
{
    $result = (new IncomeStatementReport)->handle(new ReportFilter(dateFrom: $from, dateTo: $to));

    return ['sections' => collect($result->rows)->keyBy('key')->all(), 'summary' => $result->summary];
}

test('the income statement nets the sales discount and works down to net profit', function (): void {
    ['sections' => $sections, 'summary' => $summary] = incomeStatement('2026-10-01', '2026-10-31');

    expect(collect($sections['revenue']['accounts'])->pluck('amount', 'code')->all())->toBe([
        '4101' => '5000.00',
        '4201' => '-300.00',
    ])->and($sections['revenue']['total'])->toBe('4700.00')
        ->and(collect($sections['cost_of_sales']['accounts'])->pluck('amount', 'code')->all())->toBe([
            '5101' => '2000.00',
            '5201' => '50.00',
        ])->and($sections['cost_of_sales']['total'])->toBe('2050.00')
        ->and($sections['expenses']['total'])->toBe('700.00');

    expect($summary)->toBe([
        'revenue' => '4700.00',
        'cost_of_sales' => '2050.00',
        'gross_profit' => '2650.00',
        'expenses' => '700.00',
        'net_profit' => '1950.00',
    ]);
});

test('only the period counts on the income statement', function (): void {
    expect(incomeStatement('2025-01-01', '2025-12-31')['summary'])->toBe([
        'revenue' => '1000.00',
        'cost_of_sales' => '400.00',
        'gross_profit' => '600.00',
        'expenses' => '0.00',
        'net_profit' => '600.00',
    ]);
});

test('a loss shows as a negative profit', function (): void {
    ReportFixtures::journal('2026-12-01', [['6101', '100.00', '0.00'], ['1101', '0.00', '100.00']]);

    expect(incomeStatement('2026-12-01', '2026-12-31')['summary']['net_profit'])->toBe('-100.00');
});

test('the balance sheet balances with cumulative profit across years shown as equity', function (): void {
    $result = (new BalanceSheetReport)->handle(new ReportFilter(asOf: '2026-10-31'));
    $sections = collect($result->rows)->keyBy('key')->all();

    expect(collect($sections['assets']['accounts'])->pluck('amount', 'code')->all())->toBe([
        '1101' => '6850.00',
        '1201' => '5700.00',
    ])->and($sections['assets']['total'])->toBe('12550.00')
        ->and($sections['liabilities']['total'])->toBe('0.00')
        ->and(collect($sections['equity']['accounts'])->pluck('amount', 'code')->all())->toBe([
            '3101' => '10000.00',
        ])->and($sections['equity']['current_earnings'])->toBe('2550.00')
        ->and($sections['equity']['total'])->toBe('12550.00');

    expect($result->summary)->toBe([
        'as_of' => '2026-10-31',
        'assets' => '12550.00',
        'liabilities' => '0.00',
        'equity' => '12550.00',
        'liabilities_and_equity' => '12550.00',
        'balanced' => true,
    ]);
});

test('the balance sheet stays balanced at every date and ignores what comes after it', function (string $asOf, string $assets, string $earnings): void {
    $result = (new BalanceSheetReport)->handle(new ReportFilter(asOf: $asOf));

    expect($result->summary['balanced'])->toBeTrue()
        ->and($result->summary['assets'])->toBe($assets)
        ->and(collect($result->rows)->firstWhere('key', 'equity')['current_earnings'])->toBe($earnings);
})->with([
    'before anything' => ['2024-12-31', '0.00', '0.00'],
    'after capital' => ['2025-01-10', '10000.00', '0.00'],
    'after the 2025 sale' => ['2025-12-31', '10600.00', '600.00'],
    'mid october' => ['2026-10-06', '13300.00', '3300.00'],
]);

test('an unbalanced ledger is reported as such', function (): void {
    $journal = ReportFixtures::journal('2026-10-09', [['1101', '100.00', '0.00'], ['3101', '0.00', '100.00']]);
    DB::table('journal_lines')->where('journal_id', $journal->id)->where('line_no', 1)->update(['debit' => '90.00']);

    expect((new BalanceSheetReport)->handle(new ReportFilter(asOf: '2026-10-31'))->summary['balanced'])->toBeFalse();
});
