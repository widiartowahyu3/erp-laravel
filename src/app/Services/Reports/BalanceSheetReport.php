<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\Decimal;

/**
 * Assets, liabilities and equity at a date. There is no year-end closing, so all profit so far
 * appears as one cumulative equity line.
 */
final class BalanceSheetReport
{
    public function handle(ReportFilter $filter): ReportResult
    {
        $asOf = $filter->balanceDate();
        $sections = ['asset' => [], 'liability' => [], 'equity' => []];

        foreach (AccountBalances::netByAccount(['asset', 'liability', 'equity'], null, $asOf) as $account) {
            $sections[$account->type][] = [
                'code' => $account->code,
                'name' => $account->name,
                'amount' => $account->type === 'asset' ? $account->net_debit : Decimal::sub('0', $account->net_debit),
            ];
        }

        $currentEarnings = '0.00';

        foreach (AccountBalances::netByAccount(['revenue', 'expense'], null, $asOf) as $account) {
            $currentEarnings = Decimal::sub($currentEarnings, $account->net_debit);
        }

        $assets = AccountBalances::total($sections['asset']);
        $liabilities = AccountBalances::total($sections['liability']);
        $equity = Decimal::add(AccountBalances::total($sections['equity']), $currentEarnings);
        $liabilitiesAndEquity = Decimal::add($liabilities, $equity);

        return new ReportResult(
            [
                ['key' => 'assets', 'label' => 'Aset', 'accounts' => $sections['asset'], 'total' => $assets],
                ['key' => 'liabilities', 'label' => 'Liabilitas', 'accounts' => $sections['liability'], 'total' => $liabilities],
                ['key' => 'equity', 'label' => 'Ekuitas', 'accounts' => $sections['equity'], 'current_earnings' => $currentEarnings, 'total' => $equity],
            ],
            [
                'as_of' => $asOf,
                'assets' => $assets,
                'liabilities' => $liabilities,
                'equity' => $equity,
                'liabilities_and_equity' => $liabilitiesAndEquity,
                'balanced' => Decimal::cmp($assets, $liabilitiesAndEquity) === 0,
            ]
        );
    }
}
