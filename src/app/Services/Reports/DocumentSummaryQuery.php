<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Document counts and values of one trade side for a period, with its biggest parties by invoiced value.
 */
final class DocumentSummaryQuery
{
    private const int TOP_PARTIES = 10;

    private const array ISSUED_INVOICES = ['posted', 'partially_paid', 'paid'];

    private const array UNCOMMITTED_ORDERS = ['draft', 'void'];

    /**
     * @param  array{orders: string, fulfilments: string, fulfilment_date: string, fulfilment_key: string, invoices: string, parties: string, party_column: string}  $side
     */
    public static function run(string $from, string $to, array $side): ReportResult
    {
        $orders = DB::table($side['orders'])
            ->whereBetween('order_date', [$from, $to])
            ->whereNotIn('status', self::UNCOMMITTED_ORDERS)
            ->selectRaw('COUNT(*) as document_count, COALESCE(SUM(total), 0) as document_value')
            ->first();

        $fulfilments = DB::table($side['fulfilments'])
            ->whereBetween($side['fulfilment_date'], [$from, $to])
            ->where('status', 'posted')
            ->count();

        $invoices = DB::table($side['invoices'])
            ->whereBetween('invoice_date', [$from, $to])
            ->whereIn('status', self::ISSUED_INVOICES)
            ->selectRaw('COUNT(*) as document_count, COALESCE(SUM(total), 0) as document_value')
            ->first();

        $top = DB::table("{$side['invoices']} as i")
            ->join("{$side['parties']} as p", 'p.id', '=', "i.{$side['party_column']}")
            ->whereBetween('i.invoice_date', [$from, $to])
            ->whereIn('i.status', self::ISSUED_INVOICES)
            ->groupBy('p.id', 'p.code', 'p.name')
            ->orderByDesc('value')
            ->orderBy('p.name')
            ->limit(self::TOP_PARTIES)
            ->select(['p.id', 'p.code', 'p.name'])
            ->selectRaw('COUNT(*) as invoice_count, SUM(i.total) as value')
            ->get();

        return new ReportResult(
            $top->map(fn (object $party): array => [
                'party_id' => (int) $party->id,
                'party_code' => (string) $party->code,
                'party_name' => (string) $party->name,
                'invoice_count' => (int) $party->invoice_count,
                'value' => Decimal::round((string) $party->value),
            ])->all(),
            [
                'order_count' => (int) $orders->document_count,
                'order_value' => Decimal::round((string) $orders->document_value),
                $side['fulfilment_key'] => $fulfilments,
                'invoice_count' => (int) $invoices->document_count,
                'invoice_value' => Decimal::round((string) $invoices->document_value),
            ]
        );
    }
}
