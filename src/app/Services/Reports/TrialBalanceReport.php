<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Opening, movement and closing per postable account. Balances read positive on the account's normal side.
 */
final class TrialBalanceReport
{
    /**
     * @throws BusinessRuleException
     */
    public function handle(ReportFilter $filter): ReportResult
    {
        [$from, $to] = $filter->period();

        $accounts = DB::table('chart_of_accounts as a')
            ->join('journal_lines as l', 'l.account_id', '=', 'a.id')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', 'posted')
            ->where('j.journal_date', '<=', $to)
            ->where('a.is_postable', true)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance')
            ->orderBy('a.code')
            ->select(['a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance'])
            ->selectRaw('COALESCE(SUM(CASE WHEN j.journal_date < ? THEN l.debit END), 0) as opening_debit', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN j.journal_date < ? THEN l.credit END), 0) as opening_credit', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN j.journal_date >= ? THEN l.debit END), 0) as period_debit', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN j.journal_date >= ? THEN l.credit END), 0) as period_credit', [$from])
            ->get();

        $totals = ['opening_debit' => '0.00', 'opening_credit' => '0.00', 'total_debit' => '0.00', 'total_credit' => '0.00'];
        $rows = [];

        foreach ($accounts as $account) {
            $isDebitNormal = $account->normal_balance === 'debit';
            $openingNet = Decimal::sub((string) $account->opening_debit, (string) $account->opening_credit);
            $opening = $isDebitNormal ? $openingNet : Decimal::sub('0', $openingNet);
            $movement = $isDebitNormal
                ? Decimal::sub((string) $account->period_debit, (string) $account->period_credit)
                : Decimal::sub((string) $account->period_credit, (string) $account->period_debit);

            $rows[] = [
                'account_id' => (int) $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'type' => (string) $account->type,
                'normal_balance' => (string) $account->normal_balance,
                'opening' => $opening,
                'debit' => Decimal::round((string) $account->period_debit),
                'credit' => Decimal::round((string) $account->period_credit),
                'closing' => Decimal::add($opening, $movement),
            ];

            $totals['opening_debit'] = Decimal::add($totals['opening_debit'], (string) $account->opening_debit);
            $totals['opening_credit'] = Decimal::add($totals['opening_credit'], (string) $account->opening_credit);
            $totals['total_debit'] = Decimal::add($totals['total_debit'], (string) $account->period_debit);
            $totals['total_credit'] = Decimal::add($totals['total_credit'], (string) $account->period_credit);
        }

        return new ReportResult($rows, [
            ...$totals,
            'balanced' => Decimal::cmp($totals['opening_debit'], $totals['opening_credit']) === 0
                && Decimal::cmp($totals['total_debit'], $totals['total_credit']) === 0,
        ]);
    }
}
