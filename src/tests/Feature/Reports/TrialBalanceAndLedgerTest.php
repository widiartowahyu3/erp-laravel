<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Accounting\JournalService;
use App\Services\Reports\GeneralLedgerReport;
use App\Services\Reports\ReportFilter;
use App\Services\Reports\TrialBalanceReport;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportFixtures;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
});

function trialBalance(string $from, string $to): array
{
    $result = (new TrialBalanceReport)->handle(new ReportFilter(dateFrom: $from, dateTo: $to));

    return ['rows' => collect($result->rows)->keyBy('code')->all(), 'summary' => $result->summary];
}

test('the trial balance splits opening, movement and closing on the normal side of each account', function (): void {
    ReportFixtures::journal('2026-09-15', [['1101', '1000.00', '0.00'], ['3101', '0.00', '1000.00']]);
    ReportFixtures::journal('2026-10-05', [['1101', '500.00', '0.00'], ['4101', '0.00', '500.00']]);
    ReportFixtures::journal('2026-10-20', [['6101', '200.00', '0.00'], ['1101', '0.00', '200.00']]);
    ReportFixtures::journal('2026-11-02', [['1101', '999.00', '0.00'], ['3101', '0.00', '999.00']]);

    ['rows' => $rows, 'summary' => $summary] = trialBalance('2026-10-01', '2026-10-31');

    expect($rows['1101'])->toMatchArray([
        'name' => 'Kas',
        'normal_balance' => 'debit',
        'opening' => '1000.00',
        'debit' => '500.00',
        'credit' => '200.00',
        'closing' => '1300.00',
    ])->and($rows['3101'])->toMatchArray([
        'normal_balance' => 'credit',
        'opening' => '1000.00',
        'debit' => '0.00',
        'credit' => '0.00',
        'closing' => '1000.00',
    ])->and($rows['4101'])->toMatchArray([
        'opening' => '0.00',
        'credit' => '500.00',
        'closing' => '500.00',
    ])->and($rows['6101'])->toMatchArray([
        'debit' => '200.00',
        'closing' => '200.00',
    ]);

    expect($summary)->toBe([
        'opening_debit' => '1000.00',
        'opening_credit' => '1000.00',
        'total_debit' => '700.00',
        'total_credit' => '700.00',
        'balanced' => true,
    ]);
});

test('a contra balance on an account goes negative on its normal side', function (): void {
    ReportFixtures::journal('2026-10-05', [['1101', '100.00', '0.00'], ['3101', '0.00', '100.00']]);
    ReportFixtures::journal('2026-10-06', [['3101', '150.00', '0.00'], ['1101', '0.00', '150.00']]);

    $rows = trialBalance('2026-10-01', '2026-10-31')['rows'];

    expect($rows['1101']['closing'])->toBe('-50.00')
        ->and($rows['3101']['closing'])->toBe('-50.00');
});

test('draft and voided journals do not count, while a reversal counts together with its original', function (): void {
    $service = new JournalService;

    ReportFixtures::journal('2026-10-05', [['1101', '77.00', '0.00'], ['3101', '0.00', '77.00']], false);
    $service->voidDraft(ReportFixtures::journal('2026-10-05', [['1101', '88.00', '0.00'], ['3101', '0.00', '88.00']], false), 'Salah');

    expect(trialBalance('2026-10-01', '2026-10-31')['rows'])->toBe([]);

    $posted = ReportFixtures::journal('2026-10-05', [['1101', '100.00', '0.00'], ['3101', '0.00', '100.00']]);
    $service->reverse($posted, 'Salah catat');

    $today = now()->toDateString();
    ['rows' => $rows, 'summary' => $summary] = trialBalance('2026-10-01', $today);

    expect($rows['1101']['debit'])->toBe('100.00')
        ->and($rows['1101']['credit'])->toBe('100.00')
        ->and($rows['1101']['closing'])->toBe('0.00')
        ->and($summary['balanced'])->toBeTrue();
});

test('header accounts never appear and an empty period is balanced at zero', function (): void {
    ['rows' => $rows, 'summary' => $summary] = trialBalance('2026-10-01', '2026-10-31');

    expect($rows)->toBe([])
        ->and($summary)->toBe([
            'opening_debit' => '0.00',
            'opening_credit' => '0.00',
            'total_debit' => '0.00',
            'total_credit' => '0.00',
            'balanced' => true,
        ]);
});

test('the balance check would catch lines that do not add up', function (): void {
    $journal = ReportFixtures::journal('2026-10-05', [['1101', '100.00', '0.00'], ['3101', '0.00', '100.00']]);

    DB::table('journal_lines')->where('journal_id', $journal->id)->where('line_no', 1)->update(['debit' => '90.00']);

    expect(trialBalance('2026-10-01', '2026-10-31')['summary']['balanced'])->toBeFalse();
});

test('a reversed or too long period is refused', function (array $range, string $field): void {
    try {
        (new TrialBalanceReport)->handle(new ReportFilter(dateFrom: $range[0], dateTo: $range[1]));
        $this->fail('The period should have been refused.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(422)->and($e->field)->toBe($field);
    }
})->with([
    'reversed' => [['2026-10-31', '2026-10-01'], 'date_to'],
    'longer than five years' => [['2020-01-01', '2026-01-02'], 'date_to'],
    'missing start' => [[null, '2026-10-01'], 'date_from'],
    'missing end' => [['2026-10-01', null], 'date_to'],
]);

test('exactly five years is accepted', function (): void {
    expect((new TrialBalanceReport)->handle(new ReportFilter(dateFrom: '2021-01-01', dateTo: '2026-01-01'))->rows)->toBe([]);
});

test('the ledger shows opening, running balance and closing, in journal order', function (): void {
    ReportFixtures::journal('2026-09-15', [['1101', '1000.00', '0.00'], ['3101', '0.00', '1000.00']]);
    $first = ReportFixtures::journal('2026-10-05', [['1101', '500.00', '0.00'], ['4101', '0.00', '500.00']], true, 'Masuk');
    $second = ReportFixtures::journal('2026-10-05', [['6101', '200.00', '0.00'], ['1101', '0.00', '200.00']], true, 'Keluar');
    $third = ReportFixtures::journal('2026-10-07', [['1101', '50.00', '0.00'], ['4101', '0.00', '50.00']]);
    ReportFixtures::journal('2026-11-02', [['1101', '999.00', '0.00'], ['3101', '0.00', '999.00']]);

    $result = (new GeneralLedgerReport)->handle(new ReportFilter(
        dateFrom: '2026-10-01',
        dateTo: '2026-10-31',
        accountId: ReportFixtures::accountId('1101')
    ));

    expect($result->summary)->toMatchArray([
        'account_code' => '1101',
        'normal_balance' => 'debit',
        'opening' => '1000.00',
        'total_debit' => '550.00',
        'total_credit' => '200.00',
        'closing' => '1350.00',
    ]);

    expect(array_column($result->rows, 'journal_number'))->toBe([$first->number, $second->number, $third->number])
        ->and(array_column($result->rows, 'debit'))->toBe(['500.00', '0.00', '50.00'])
        ->and(array_column($result->rows, 'credit'))->toBe(['0.00', '200.00', '0.00'])
        ->and(array_column($result->rows, 'balance'))->toBe(['1500.00', '1300.00', '1350.00'])
        ->and($result->rows[0]['description'])->toBe('Masuk')
        ->and($result->rows[0]['journal_date'])->toBe('2026-10-05')
        ->and($result->rows[0]['source_label'])->toBeNull();
});

test('a credit-side account runs its balance the other way', function (): void {
    ReportFixtures::journal('2026-09-15', [['1101', '1000.00', '0.00'], ['3101', '0.00', '1000.00']]);
    ReportFixtures::journal('2026-10-05', [['1101', '500.00', '0.00'], ['3101', '0.00', '500.00']]);
    ReportFixtures::journal('2026-10-06', [['3101', '100.00', '0.00'], ['1101', '0.00', '100.00']]);

    $result = (new GeneralLedgerReport)->handle(new ReportFilter(
        dateFrom: '2026-10-01',
        dateTo: '2026-10-31',
        accountId: ReportFixtures::accountId('3101')
    ));

    expect(array_column($result->rows, 'balance'))->toBe(['1500.00', '1400.00'])
        ->and($result->summary['closing'])->toBe('1400.00');
});

test('later pages carry the running balance forward and the closing covers every page', function (): void {
    foreach (['10.00', '20.00', '30.00', '40.00', '50.00'] as $amount) {
        ReportFixtures::journal('2026-10-05', [['1101', $amount, '0.00'], ['3101', '0.00', $amount]]);
    }

    $filter = fn (int $page): ReportFilter => new ReportFilter(
        dateFrom: '2026-10-01',
        dateTo: '2026-10-31',
        accountId: ReportFixtures::accountId('1101'),
        page: $page,
        perPage: 2
    );

    $second = (new GeneralLedgerReport)->handle($filter(2));

    expect(array_column($second->rows, 'balance'))->toBe(['60.00', '100.00'])
        ->and($second->summary['closing'])->toBe('150.00')
        ->and($second->meta)->toMatchArray(['page' => 2, 'per_page' => 2, 'total' => 5, 'last_page' => 3, 'from' => 3, 'to' => 4]);
});

test('draft journals stay out of the ledger', function (): void {
    ReportFixtures::journal('2026-10-05', [['1101', '77.00', '0.00'], ['3101', '0.00', '77.00']], false);

    $result = (new GeneralLedgerReport)->handle(new ReportFilter(
        dateFrom: '2026-10-01',
        dateTo: '2026-10-31',
        accountId: ReportFixtures::accountId('1101')
    ));

    expect($result->rows)->toBe([])
        ->and($result->summary['closing'])->toBe('0.00');
});

test('a header or unknown account is refused for the ledger', function (string $case): void {
    $id = $case === 'header' ? ReportFixtures::accountId('1000') : 999999999;

    try {
        (new GeneralLedgerReport)->handle(new ReportFilter(dateFrom: '2026-10-01', dateTo: '2026-10-31', accountId: $id));
        $this->fail('The account should have been refused.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(422)->and($e->field)->toBe('account_id');
    }
})->with(['header', 'unknown']);
