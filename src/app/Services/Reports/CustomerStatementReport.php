<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One customer's invoices and payments in date order, with what they owe after each entry.
 * Each allocation is its own line, so a payment across invoices shows where the money went.
 */
final class CustomerStatementReport
{
    private const array ISSUED_STATUSES = ['posted', 'partially_paid', 'paid'];

    /**
     * @throws BusinessRuleException
     */
    public function handle(ReportFilter $filter): ReportResult
    {
        [$from, $to] = $filter->period();
        $customer = $filter->customerId === null ? null : Customer::query()->find($filter->customerId);

        if ($customer === null) {
            throw new BusinessRuleException('Pelanggan tidak ditemukan.', 422, 'customer_id');
        }

        $opening = Decimal::sub(
            (string) $this->invoices($customer->id)->where('i.invoice_date', '<', $from)->sum('i.total'),
            (string) $this->allocations($customer->id)->where('p.payment_date', '<', $from)->sum('a.amount'),
        );

        $entries = $this->invoices($customer->id)
            ->whereBetween('i.invoice_date', [$from, $to])
            ->selectRaw("'invoice' as kind, 0 as entry_rank, i.id as document_id, 0 as line_id, i.number as number, NULL as applied_to, i.invoice_date as entry_date, i.total as debit, 0 as credit")
            ->unionAll(
                $this->allocations($customer->id)
                    ->whereBetween('p.payment_date', [$from, $to])
                    ->selectRaw("'payment' as kind, 1 as entry_rank, p.id as document_id, a.id as line_id, p.number as number, i.number as applied_to, p.payment_date as entry_date, 0 as debit, a.amount as credit")
            )
            ->orderBy('entry_date')
            ->orderBy('entry_rank')
            ->orderBy('document_id')
            ->orderBy('line_id')
            ->get();

        $balance = $opening;
        $totalDebit = '0.00';
        $totalCredit = '0.00';
        $rows = [];

        foreach ($entries as $entry) {
            $debit = Decimal::round((string) $entry->debit);
            $credit = Decimal::round((string) $entry->credit);
            $balance = Decimal::sub(Decimal::add($balance, $debit), $credit);
            $totalDebit = Decimal::add($totalDebit, $debit);
            $totalCredit = Decimal::add($totalCredit, $credit);

            $rows[] = [
                'kind' => (string) $entry->kind,
                'document_id' => (int) $entry->document_id,
                'number' => (string) $entry->number,
                'applied_to' => $entry->applied_to === null ? null : (string) $entry->applied_to,
                'entry_date' => (string) $entry->entry_date,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balance,
            ];
        }

        return new ReportResult($rows, [
            'customer_id' => $customer->id,
            'customer_code' => $customer->code,
            'customer_name' => $customer->name,
            'opening' => $opening,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'closing' => $balance,
        ]);
    }

    private function invoices(int $customerId): Builder
    {
        return DB::table('sales_invoices as i')
            ->where('i.customer_id', $customerId)
            ->whereIn('i.status', self::ISSUED_STATUSES);
    }

    private function allocations(int $customerId): Builder
    {
        return DB::table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->join('sales_invoices as i', 'i.id', '=', 'a.sales_invoice_id')
            ->where('i.customer_id', $customerId)
            ->where('p.status', 'posted');
    }
}
