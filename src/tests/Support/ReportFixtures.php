<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Journal;
use App\Services\Accounting\JournalService;
use Illuminate\Support\Facades\DB;

final class ReportFixtures
{
    public static function accountId(string $code): int
    {
        return (int) DB::table('chart_of_accounts')->where('code', $code)->value('id');
    }

    /**
     * A manual journal, posted unless asked otherwise.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $lines  account code, debit, credit
     */
    public static function journal(string $date, array $lines, bool $post = true, string $description = 'Jurnal uji'): Journal
    {
        $service = new JournalService;

        $journal = $service->createDraft([
            'journal_date' => $date,
            'description' => $description,
            'lines' => array_map(fn (array $line): array => [
                'account_id' => self::accountId($line[0]),
                'debit' => $line[1],
                'credit' => $line[2],
            ], $lines),
        ]);

        return $post ? $service->post($journal) : $journal;
    }

    /**
     * A stored invoice row with exact dates and amounts, for reports that only read it.
     *
     * @param  'sales_invoices'|'purchase_invoices'  $table
     */
    public static function invoiceRow(string $table, int $partyId, string $dueDate, string $total, string $paid = '0.00', string $status = 'posted', string $invoiceDate = '2026-01-01'): int
    {
        $isSales = $table === 'sales_invoices';

        return (int) DB::table($table)->insertGetId(array_merge([
            'number' => ($isSales ? 'SI-' : 'PI-').bin2hex(random_bytes(6)),
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'status' => $status,
            'subtotal' => $total,
            'total' => $total,
            'paid_amount' => $paid,
            'created_at' => now(),
            'updated_at' => now(),
        ], $isSales
            ? ['customer_id' => $partyId]
            : ['supplier_id' => $partyId, 'supplier_invoice_number' => 'INV-'.bin2hex(random_bytes(6))]));
    }
}
