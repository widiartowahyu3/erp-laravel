<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What each party still owes or is owed today, spread over how long it has been past due.
 * Paid amounts are only known as they stand now, so aging has no historical date.
 */
final class AgingQuery
{
    private const array OPEN_STATUSES = ['posted', 'partially_paid'];

    public const array BUCKETS = ['not_due', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90'];

    /**
     * @param  'sales_invoices'|'purchase_invoices'  $invoiceTable
     * @param  'customers'|'suppliers'  $partyTable
     * @param  'customer_id'|'supplier_id'  $partyColumn
     */
    public static function run(string $invoiceTable, string $partyTable, string $partyColumn): ReportResult
    {
        $today = now()->toDateString();

        $parties = self::openInvoices($invoiceTable, $partyColumn)
            ->join("{$partyTable} as p", 'p.id', '=', "i.{$partyColumn}")
            ->groupBy('p.id', 'p.code', 'p.name')
            ->orderBy('p.name')
            ->orderBy('p.id')
            ->select(['p.id', 'p.code', 'p.name'])
            ->selectRaw('SUM(CASE WHEN DATEDIFF(?, i.due_date) <= 0 THEN i.total - i.paid_amount ELSE 0 END) as not_due', [$today])
            ->selectRaw('SUM(CASE WHEN DATEDIFF(?, i.due_date) BETWEEN 1 AND 30 THEN i.total - i.paid_amount ELSE 0 END) as days_1_30', [$today])
            ->selectRaw('SUM(CASE WHEN DATEDIFF(?, i.due_date) BETWEEN 31 AND 60 THEN i.total - i.paid_amount ELSE 0 END) as days_31_60', [$today])
            ->selectRaw('SUM(CASE WHEN DATEDIFF(?, i.due_date) BETWEEN 61 AND 90 THEN i.total - i.paid_amount ELSE 0 END) as days_61_90', [$today])
            ->selectRaw('SUM(CASE WHEN DATEDIFF(?, i.due_date) > 90 THEN i.total - i.paid_amount ELSE 0 END) as days_over_90', [$today])
            ->selectRaw('SUM(i.total - i.paid_amount) as total')
            ->get();

        $invoices = self::openInvoices($invoiceTable, $partyColumn)
            ->orderBy('i.due_date')
            ->orderBy('i.id')
            ->select(['i.id', 'i.number', 'i.invoice_date', 'i.due_date', 'i.total', 'i.paid_amount', "i.{$partyColumn} as party_id"])
            ->selectRaw('i.total - i.paid_amount as outstanding')
            ->selectRaw('DATEDIFF(?, i.due_date) as days_past_due', [$today])
            ->get()
            ->groupBy('party_id');

        $summary = array_fill_keys([...self::BUCKETS, 'total'], '0.00');
        $rows = [];

        foreach ($parties as $party) {
            $row = [
                'party_id' => (int) $party->id,
                'party_code' => (string) $party->code,
                'party_name' => (string) $party->name,
            ];

            foreach ([...self::BUCKETS, 'total'] as $key) {
                $row[$key] = Decimal::round((string) $party->{$key});
                $summary[$key] = Decimal::add($summary[$key], $row[$key]);
            }

            $row['invoices'] = ($invoices[$party->id] ?? collect())->map(fn (object $invoice): array => [
                'id' => (int) $invoice->id,
                'number' => (string) $invoice->number,
                'invoice_date' => (string) $invoice->invoice_date,
                'due_date' => (string) $invoice->due_date,
                'total' => Decimal::round((string) $invoice->total),
                'paid_amount' => Decimal::round((string) $invoice->paid_amount),
                'outstanding' => Decimal::round((string) $invoice->outstanding),
                'days_overdue' => max(0, (int) $invoice->days_past_due),
                'bucket' => self::bucketOf((int) $invoice->days_past_due),
            ])->values()->all();

            $rows[] = $row;
        }

        return new ReportResult($rows, ['as_of' => $today, ...$summary]);
    }

    private static function openInvoices(string $invoiceTable, string $partyColumn): Builder
    {
        return DB::table("{$invoiceTable} as i")
            ->whereIn('i.status', self::OPEN_STATUSES)
            ->whereColumn('i.total', '>', 'i.paid_amount')
            ->whereNotNull("i.{$partyColumn}");
    }

    private static function bucketOf(int $daysPastDue): string
    {
        return match (true) {
            $daysPastDue <= 0 => 'not_due',
            $daysPastDue <= 30 => 'days_1_30',
            $daysPastDue <= 60 => 'days_31_60',
            $daysPastDue <= 90 => 'days_61_90',
            default => 'days_over_90',
        };
    }
}
