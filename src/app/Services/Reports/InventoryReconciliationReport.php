<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Compares what stock is worth now against the balance of the inventory account.
 * Stock only exists as it stands today, so this report has no date.
 */
final class InventoryReconciliationReport
{
    public function handle(ReportFilter $filter): ReportResult
    {
        $accountCode = (string) config('accounting.accounts.inventory');

        $warehouses = DB::table('stock_balances as b')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->groupBy('w.id', 'w.code', 'w.name')
            ->orderBy('w.code')
            ->select(['w.id', 'w.code', 'w.name'])
            ->selectRaw('SUM(ROUND(b.quantity * b.avg_cost, 2)) as stock_value')
            ->get();

        $ledger = DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.status', 'posted')
            ->where('a.code', $accountCode)
            ->selectRaw('COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) as balance')
            ->value('balance');

        $stockValue = '0.00';
        $rows = [];

        foreach ($warehouses as $warehouse) {
            $value = Decimal::round((string) $warehouse->stock_value);
            $stockValue = Decimal::add($stockValue, $value);

            $rows[] = [
                'warehouse_id' => (int) $warehouse->id,
                'warehouse_code' => (string) $warehouse->code,
                'warehouse_name' => (string) $warehouse->name,
                'stock_value' => $value,
            ];
        }

        $ledgerBalance = Decimal::round((string) $ledger);
        $difference = Decimal::sub($stockValue, $ledgerBalance);

        return new ReportResult($rows, [
            'account_code' => $accountCode,
            'stock_value' => $stockValue,
            'ledger_balance' => $ledgerBalance,
            'difference' => $difference,
            'status' => Decimal::isZero($difference) ? 'matched' : 'difference',
        ]);
    }
}
