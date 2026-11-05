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
}
