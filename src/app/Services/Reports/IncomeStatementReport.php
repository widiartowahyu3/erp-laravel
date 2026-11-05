<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use App\Support\Decimal;

/**
 * Revenue, cost of sales and expenses for a period. A contra account shows negative within its section.
 */
final class IncomeStatementReport
{
    private const string COST_OF_SALES_PREFIX = '5';

    /**
     * @throws BusinessRuleException
     */
    public function handle(ReportFilter $filter): ReportResult
    {
        [$from, $to] = $filter->period();

        $sections = ['revenue' => [], 'cost_of_sales' => [], 'expenses' => []];

        foreach (AccountBalances::netByAccount(['revenue', 'expense'], $from, $to) as $account) {
            $isRevenue = $account->type === 'revenue';
            $key = match (true) {
                $isRevenue => 'revenue',
                str_starts_with($account->code, self::COST_OF_SALES_PREFIX) => 'cost_of_sales',
                default => 'expenses',
            };

            $sections[$key][] = [
                'code' => $account->code,
                'name' => $account->name,
                'amount' => $isRevenue ? Decimal::sub('0', $account->net_debit) : $account->net_debit,
            ];
        }

        $revenue = AccountBalances::total($sections['revenue']);
        $costOfSales = AccountBalances::total($sections['cost_of_sales']);
        $expenses = AccountBalances::total($sections['expenses']);
        $grossProfit = Decimal::sub($revenue, $costOfSales);

        return new ReportResult(
            [
                ['key' => 'revenue', 'label' => 'Pendapatan', 'accounts' => $sections['revenue'], 'total' => $revenue],
                ['key' => 'cost_of_sales', 'label' => 'Beban Pokok', 'accounts' => $sections['cost_of_sales'], 'total' => $costOfSales],
                ['key' => 'expenses', 'label' => 'Beban Operasional', 'accounts' => $sections['expenses'], 'total' => $expenses],
            ],
            [
                'revenue' => $revenue,
                'cost_of_sales' => $costOfSales,
                'gross_profit' => $grossProfit,
                'expenses' => $expenses,
                'net_profit' => Decimal::sub($grossProfit, $expenses),
            ]
        );
    }
}
