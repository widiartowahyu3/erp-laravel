<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use Carbon\Carbon;
use Database\Seeders\ChartOfAccountSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportFixtures;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    Carbon::setTestNow('2026-11-20 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('the finance cards show this month profit and all cash and bank', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance']));

    // Capital from last year stays in cash but is no profit.
    ReportFixtures::journal('2025-01-10', [['1102', '10000.00', '0.00'], ['3101', '0.00', '10000.00']]);
    // Last month profit is not this month profit, though its cash remains.
    ReportFixtures::journal('2026-10-10', [['1101', '500.00', '0.00'], ['4101', '0.00', '500.00']]);
    // This month: sale 1000 less expense 250.
    ReportFixtures::journal('2026-11-05', [['1101', '1000.00', '0.00'], ['4101', '0.00', '1000.00']]);
    ReportFixtures::journal('2026-11-06', [['6101', '250.00', '0.00'], ['1102', '0.00', '250.00']]);
    // A draft and a future date count for nothing yet.
    ReportFixtures::journal('2026-11-07', [['1101', '999.00', '0.00'], ['4101', '0.00', '999.00']], false);
    ReportFixtures::journal('2026-11-25', [['1101', '777.00', '0.00'], ['4101', '0.00', '777.00']]);

    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('finance.month_profit', '750.00')
        ->where('finance.cash_and_bank', '11250.00'));
});

test('a loss reads negative', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_finance']));
    ReportFixtures::journal('2026-11-06', [['6101', '250.00', '0.00'], ['1102', '0.00', '250.00']]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('finance.month_profit', '-250.00')
        ->where('finance.cash_and_bank', '-250.00'));
});

test('the finance cards stay away from anyone without the permission', function (): void {
    $this->actingAs(userWithPermissions(['reports.view_sales', 'reports.view_inventory', 'reports.view_purchasing']));

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('finance', null));
});
