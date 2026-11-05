<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Net debit minus credit per account from posted journals, as the shared base of the financial statements.
 */
final class AccountBalances
{
    /**
     * @param  list<string>  $types
     * @return Collection<int, stdClass>
     */
    public static function netByAccount(array $types, ?string $from, string $to): Collection
    {
        return DB::table('chart_of_accounts as a')
            ->join('journal_lines as l', 'l.account_id', '=', 'a.id')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', 'posted')
            ->where('j.journal_date', '<=', $to)
            ->when($from !== null, fn ($query) => $query->where('j.journal_date', '>=', $from))
            ->whereIn('a.type', $types)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->select(['a.code', 'a.name', 'a.type'])
            ->selectRaw('SUM(l.debit) - SUM(l.credit) as net_debit')
            ->get()
            ->map(function (stdClass $row): stdClass {
                $row->net_debit = Decimal::round((string) $row->net_debit);

                return $row;
            });
    }

    /**
     * @param  iterable<array{amount: string}>  $accounts
     */
    public static function total(iterable $accounts): string
    {
        $total = '0.00';

        foreach ($accounts as $account) {
            $total = Decimal::add($total, $account['amount']);
        }

        return $total;
    }
}
