<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\ChartOfAccount;
use App\Services\Accounting\JournalSourceResolver;
use App\Support\Decimal;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every posted line of one account in journal order, with a running balance on its normal side.
 */
final class GeneralLedgerReport
{
    /**
     * @throws BusinessRuleException
     */
    public function handle(ReportFilter $filter): ReportResult
    {
        [$from, $to] = $filter->period();
        $account = $this->postableAccount($filter->accountId);

        $opening = $this->balanceBefore($account, $from);
        $totals = $this->periodTotals($account->id, $from, $to);
        $total = (int) $totals->line_count;
        $perPage = max(1, $filter->perPage);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $filter->page), $lastPage);
        $offset = ($page - 1) * $perPage;

        $balance = $this->advance($account, $opening, ...$this->sumOfFirstLines($account->id, $from, $to, $offset));
        $rows = [];

        foreach ($this->orderedLines($account->id, $from, $to)->offset($offset)->limit($perPage)->get() as $line) {
            $balance = $this->advance($account, $balance, (string) $line->debit, (string) $line->credit);
            $rows[] = $this->rowFor($line, $balance);
        }

        return new ReportResult(
            $rows,
            [
                'account_id' => $account->id,
                'account_code' => $account->code,
                'account_name' => $account->name,
                'normal_balance' => $account->normal_balance->value,
                'opening' => $opening,
                'total_debit' => Decimal::round((string) $totals->total_debit),
                'total_credit' => Decimal::round((string) $totals->total_credit),
                'closing' => $this->advance($account, $opening, (string) $totals->total_debit, (string) $totals->total_credit),
            ],
            [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? null : $offset + 1,
                'to' => $total === 0 ? null : $offset + count($rows),
            ]
        );
    }

    /**
     * The balance brought forward, then every row of the period, read one at a time for export.
     *
     * @return Generator<int, array<string, mixed>>
     *
     * @throws BusinessRuleException
     */
    public function exportRows(ReportFilter $filter): Generator
    {
        [$from, $to] = $filter->period();
        $account = $this->postableAccount($filter->accountId);
        $balance = $this->balanceBefore($account, $from);

        yield ['journal_date' => $from, 'description' => 'Saldo awal', 'balance' => $balance];

        foreach ($this->orderedLines($account->id, $from, $to)->cursor() as $line) {
            $balance = $this->advance($account, $balance, (string) $line->debit, (string) $line->credit);

            yield $this->rowFor($line, $balance);
        }
    }

    /**
     * How many rows an export holds, including the balance brought forward.
     *
     * @throws BusinessRuleException
     */
    public function lineCount(ReportFilter $filter): int
    {
        [$from, $to] = $filter->period();
        $account = $this->postableAccount($filter->accountId);

        return (int) $this->periodTotals($account->id, $from, $to)->line_count + 1;
    }

    /**
     * @throws BusinessRuleException
     */
    private function postableAccount(?int $accountId): ChartOfAccount
    {
        $account = $accountId === null ? null : ChartOfAccount::query()->find($accountId);

        if ($account === null) {
            throw new BusinessRuleException('Akun tidak ditemukan.', 422, 'account_id');
        }

        if (! $account->is_postable) {
            throw new BusinessRuleException('Akun induk tidak memiliki buku besar. Pilih akun rinci.', 422, 'account_id');
        }

        return $account;
    }

    private function postedLines(int $accountId): Builder
    {
        return DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $accountId)
            ->where('j.status', 'posted');
    }

    private function orderedLines(int $accountId, string $from, string $to): Builder
    {
        return $this->postedLines($accountId)
            ->whereBetween('j.journal_date', [$from, $to])
            ->orderBy('j.journal_date')
            ->orderBy('j.id')
            ->orderBy('l.line_no')
            ->select(['j.id as journal_id', 'j.number', 'j.journal_date', 'j.description', 'j.source_type', 'j.source_id', 'l.debit', 'l.credit']);
    }

    private function balanceBefore(ChartOfAccount $account, string $from): string
    {
        $sums = $this->postedLines($account->id)
            ->where('j.journal_date', '<', $from)
            ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
            ->first();

        return $this->advance($account, '0.00', (string) $sums->debit, (string) $sums->credit);
    }

    /**
     * @return object{total_debit: string, total_credit: string, line_count: int}
     */
    private function periodTotals(int $accountId, string $from, string $to): object
    {
        return $this->postedLines($accountId)
            ->whereBetween('j.journal_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(l.debit), 0) as total_debit, COALESCE(SUM(l.credit), 0) as total_credit, COUNT(*) as line_count')
            ->first();
    }

    /**
     * @return array{string, string} debit and credit of the lines before the requested page
     */
    private function sumOfFirstLines(int $accountId, string $from, string $to, int $count): array
    {
        if ($count === 0) {
            return ['0.00', '0.00'];
        }

        $sums = DB::query()
            ->fromSub($this->orderedLines($accountId, $from, $to)->limit($count), 'earlier')
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        return [(string) $sums->debit, (string) $sums->credit];
    }

    private function advance(ChartOfAccount $account, string $balance, string $debit, string $credit): string
    {
        $movement = $account->normal_balance->value === 'debit'
            ? Decimal::sub($debit, $credit)
            : Decimal::sub($credit, $debit);

        return Decimal::add($balance, $movement);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(object $line, string $balance): array
    {
        return [
            'journal_id' => (int) $line->journal_id,
            'journal_number' => (string) $line->number,
            'journal_date' => (string) $line->journal_date,
            'description' => (string) $line->description,
            'source_label' => JournalSourceResolver::label($line->source_type),
            'source_url' => JournalSourceResolver::url($line->source_type, $line->source_id === null ? null : (int) $line->source_id),
            'debit' => Decimal::round((string) $line->debit),
            'credit' => Decimal::round((string) $line->credit),
            'balance' => $balance,
        ];
    }
}
