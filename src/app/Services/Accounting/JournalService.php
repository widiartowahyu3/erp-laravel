<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\DocumentType;
use App\Enums\JournalStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\ChartOfAccount;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentNumberService;
use App\Services\Documents\DocumentStateMachine;
use App\Support\Accounting\JournalDraft;
use App\Support\Concerns\RequiresTransaction;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Manual journals, automatic journals and their reversals.
 *
 * Global lock order: customers/suppliers -> SO/PO -> DO/GR/stock adjustment -> SO/PO items ->
 * DO/GR items -> payments -> invoices -> stock_balances -> document_sequences -> journals.
 * Automatic journals are therefore written last, inside the source document's transaction.
 */
final class JournalService
{
    use RequiresTransaction;

    private const string MONEY_PATTERN = '/^\d{1,16}(\.\d{1,2})?$/';

    private const int MAX_VOID_REASON = 255;

    private const int MAX_REVERSE_REASON = 200;

    public function __construct(
        private readonly AccountResolver $accounts = new AccountResolver
    ) {}

    /**
     * Create a draft manual journal.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function createDraft(array $data): Journal
    {
        if (! empty($data['source_type']) || ! empty($data['source_id'])) {
            throw new BusinessRuleException('Jurnal manual tidak boleh memiliki dokumen sumber.', 422, 'source_type');
        }

        return DB::transaction(function () use ($data): Journal {
            $lines = $this->normalizeManualLines($data['lines'] ?? null);
            [$totalDebit, $totalCredit] = $this->assertBalanced($lines);

            $journal = Journal::create([
                'number' => DocumentNumberService::generate(DocumentType::JV, (string) $data['journal_date']),
                'journal_date' => (string) $data['journal_date'],
                'description' => trim((string) $data['description']),
                'status' => JournalStatus::DRAFT,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ]);

            $this->writeLines($journal, $lines);

            return $journal->load('lines');
        });
    }

    /**
     * Update a draft manual journal and replace its lines.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     * @throws DocumentNotEditableException
     */
    public function updateDraft(Journal $journal, array $data): Journal
    {
        return DB::transaction(function () use ($journal, $data): Journal {
            $locked = $this->lock($journal);

            if (! $locked->isEditable()) {
                throw new DocumentNotEditableException('Hanya draf jurnal yang dapat diubah.');
            }

            $lines = $this->normalizeManualLines($data['lines'] ?? null);
            [$totalDebit, $totalCredit] = $this->assertBalanced($lines);

            $locked->journal_date = (string) $data['journal_date'];
            $locked->description = trim((string) $data['description']);
            $locked->total_debit = $totalDebit;
            $locked->total_credit = $totalCredit;
            $locked->save();

            // @audit-bypass: journal_lines has no audit columns; the new lines are audited explicitly in writeLines
            JournalLine::query()->where('journal_id', $locked->id)->delete();

            $this->writeLines($locked, $lines);

            return $locked->load('lines');
        });
    }

    /**
     * Cancel a draft manual journal.
     *
     * @throws BusinessRuleException
     * @throws DocumentNotEditableException
     */
    public function voidDraft(Journal $journal, string $reason): Journal
    {
        return DB::transaction(function () use ($journal, $reason): Journal {
            $cleanReason = $this->cleanReason($reason, self::MAX_VOID_REASON);
            $locked = $this->lock($journal);

            if (! $locked->isEditable()) {
                throw new DocumentNotEditableException('Hanya draf jurnal yang dapat dibatalkan.');
            }

            $locked->status = JournalStatus::VOID;
            $locked->voided_by = $this->actorId();
            $locked->voided_at = now();
            $locked->void_reason = $cleanReason;
            $locked->save();

            return $locked->load('lines');
        });
    }

    /**
     * Post a draft manual journal.
     *
     * @throws BusinessRuleException
     * @throws InvalidStateTransitionException
     */
    public function post(Journal $journal): Journal
    {
        return DB::transaction(function () use ($journal): Journal {
            $locked = $this->lock($journal);

            DocumentStateMachine::assertTransition($locked->status, JournalStatus::POSTED);

            $lines = $locked->lines()->get();

            if ($lines->count() < 2) {
                throw new BusinessRuleException('Jurnal harus memiliki minimal 2 baris.', 422, 'lines');
            }

            foreach ($lines as $line) {
                $this->assertAccountPostable($line->account_id);
            }

            $locked->status = JournalStatus::POSTED;
            $locked->posted_by = $this->actorId();
            $locked->posted_at = now();
            $locked->save();

            return $locked->load('lines');
        });
    }

    /**
     * Reverse a posted manual journal. Automatic journals are reversed by voiding their source document.
     *
     * @throws BusinessRuleException
     */
    public function reverse(Journal $journal, string $reason): Journal
    {
        return DB::transaction(function () use ($journal, $reason): Journal {
            $cleanReason = $this->cleanReason($reason, self::MAX_REVERSE_REASON);
            $locked = $this->lock($journal);

            if ($locked->status !== JournalStatus::POSTED) {
                throw new BusinessRuleException('Hanya jurnal yang sudah diposting yang dapat dibalik.', 409, 'status');
            }

            if ($locked->source_type !== null) {
                throw new BusinessRuleException('Jurnal otomatis tidak dapat dibalik langsung. Batalkan dokumen sumbernya.', 409, 'status');
            }

            if ($locked->reversal_of_id !== null) {
                throw new BusinessRuleException('Jurnal pembalik tidak dapat dibalik.', 409, 'status');
            }

            if (Journal::query()->where('reversal_of_id', $locked->id)->exists()) {
                throw new BusinessRuleException('Jurnal ini sudah pernah dibalik.', 409, 'status');
            }

            return $this->createReversal($locked, "Pembalik {$locked->number}: {$cleanReason}", now());
        });
    }

    /**
     * Post the journal of a source document. Idempotent: a source that already has its journal gets that same journal back.
     *
     * @return Journal|null Null when every amount is zero, so no journal exists for the source.
     *
     * @throws BusinessRuleException
     * @throws \LogicException
     */
    public function postAutomatic(JournalDraft $draft, string $sourceType, int $sourceId, CarbonInterface $date): ?Journal
    {
        self::ensureInTransaction();

        $existing = $this->findOriginal($sourceType, $sourceId);

        if ($existing !== null) {
            return $existing->load('lines');
        }

        if ($draft->isEmpty()) {
            AuditLogger::record(
                event: 'journal.skipped',
                auditableType: $sourceType,
                auditableId: $sourceId,
                new: ['reason' => 'Nilai jurnal nol'],
                strict: true
            );

            return null;
        }

        $lines = array_map(fn (array $line): array => [
            'account_id' => $this->resolveAccount($line['account']),
            'debit' => $line['debit'],
            'credit' => $line['credit'],
            'description' => $line['description'],
        ], $draft->lines());

        [$totalDebit, $totalCredit] = $this->assertBalanced($lines);

        $journal = Journal::create([
            'number' => DocumentNumberService::generate(DocumentType::JV, $date),
            'journal_date' => $date->toDateString(),
            'description' => mb_substr($draft->description, 0, 255),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'status' => JournalStatus::POSTED,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'posted_by' => $this->actorId(),
            'posted_at' => now(),
        ]);

        $this->writeLines($journal, $lines);

        return $journal->load('lines');
    }

    /**
     * Reverse the journal of a voided source document. Idempotent, and a no-op when the source never had a journal.
     *
     * @throws \LogicException
     */
    public function reverseAutomatic(string $sourceType, int $sourceId, CarbonInterface $date): ?Journal
    {
        self::ensureInTransaction();

        $original = $this->findOriginal($sourceType, $sourceId);

        if ($original === null) {
            AuditLogger::record(
                event: 'journal.reversal_skipped',
                auditableType: $sourceType,
                auditableId: $sourceId,
                new: ['reason' => 'Dokumen sumber tidak memiliki jurnal'],
                strict: true
            );

            return null;
        }

        $existing = Journal::query()->where('reversal_of_id', $original->id)->first();

        if ($existing !== null) {
            return $existing->load('lines');
        }

        return $this->createReversal($original, "Pembalik {$original->number}", $date);
    }

    /**
     * Copy a journal with debit and credit swapped, never recalculating the amounts.
     */
    private function createReversal(Journal $original, string $description, CarbonInterface $date): Journal
    {
        $reversal = Journal::create([
            'number' => DocumentNumberService::generate(DocumentType::JV, $date),
            'journal_date' => $date->toDateString(),
            'description' => mb_substr($description, 0, 255),
            'source_type' => $original->source_type,
            'source_id' => $original->source_id,
            'status' => JournalStatus::POSTED,
            'total_debit' => $original->total_credit,
            'total_credit' => $original->total_debit,
            'reversal_of_id' => $original->id,
            'posted_by' => $this->actorId(),
            'posted_at' => now(),
        ]);

        $lines = $original->lines()->get()->map(fn (JournalLine $line): array => [
            'account_id' => $line->account_id,
            'debit' => $line->credit,
            'credit' => $line->debit,
            'description' => $line->description,
        ])->all();

        $this->writeLines($reversal, $lines);

        return $reversal->load('lines');
    }

    private function findOriginal(string $sourceType, int $sourceId): ?Journal
    {
        return Journal::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNull('reversal_of_id')
            ->lockForUpdate()
            ->first();
    }

    private function lock(Journal $journal): Journal
    {
        return Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
    }

    private function actorId(): ?int
    {
        return Auth::id() ? (int) Auth::id() : null;
    }

    /**
     * Automatic drafts name accounts by logical key or by account code.
     */
    private function resolveAccount(string $account): int
    {
        return is_string(config("accounting.accounts.{$account}"))
            ? $this->accounts->id($account)
            : $this->accounts->idByCode($account);
    }

    /**
     * @throws BusinessRuleException
     */
    private function cleanReason(string $reason, int $maxLength): string
    {
        $clean = trim($reason);

        if ($clean === '' || mb_strlen($clean) > $maxLength) {
            throw new BusinessRuleException("Alasan wajib diisi (maksimal {$maxLength} karakter).", 422, 'reason');
        }

        return $clean;
    }

    /**
     * Validate manual lines down to the exact money format, one-sided amounts and postable accounts.
     *
     * @return list<array{account_id: int, debit: string, credit: string, description: string|null}>
     *
     * @throws BusinessRuleException
     */
    private function normalizeManualLines(mixed $lines): array
    {
        if (! is_array($lines) || count($lines) < 2) {
            throw new BusinessRuleException('Jurnal harus memiliki minimal 2 baris.', 422, 'lines');
        }

        $normalized = [];

        foreach (array_values($lines) as $index => $line) {
            $lineNo = $index + 1;
            $debit = $this->parseMoney($line['debit'] ?? '0', $lineNo);
            $credit = $this->parseMoney($line['credit'] ?? '0', $lineNo);

            if (Decimal::isZero($debit) === Decimal::isZero($credit)) {
                throw new BusinessRuleException("Baris ke-{$lineNo} harus berisi debit atau kredit saja.", 422, 'lines');
            }

            $accountId = (int) ($line['account_id'] ?? 0);
            $this->assertAccountPostable($accountId, $lineNo);

            $description = isset($line['description']) ? trim((string) $line['description']) : '';

            $normalized[] = [
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'description' => $description !== '' ? $description : null,
            ];
        }

        return $normalized;
    }

    /**
     * @throws BusinessRuleException
     */
    private function parseMoney(mixed $value, int $lineNo): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new BusinessRuleException("Nilai pada baris ke-{$lineNo} harus berupa teks desimal.", 422, 'lines');
        }

        $text = trim((string) $value);

        if (preg_match(self::MONEY_PATTERN, $text) !== 1) {
            throw new BusinessRuleException("Nilai pada baris ke-{$lineNo} tidak valid.", 422, 'lines');
        }

        return Decimal::round($text, Decimal::SCALE_MONEY);
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertAccountPostable(int $accountId, ?int $lineNo = null): void
    {
        $account = ChartOfAccount::query()->whereKey($accountId)->first();
        $where = $lineNo !== null ? "pada baris ke-{$lineNo} " : '';

        if ($account === null) {
            throw new BusinessRuleException("Akun {$where}tidak ditemukan.", 422, 'lines');
        }

        if (! $account->is_active) {
            throw new BusinessRuleException("Akun {$account->code} {$where}tidak aktif.", 422, 'lines');
        }

        if (! $account->is_postable) {
            throw new BusinessRuleException("Akun {$account->code} {$where}adalah akun induk.", 422, 'lines');
        }
    }

    /**
     * @param  list<array{account_id: int, debit: string, credit: string, description: string|null}>  $lines
     * @return array{string, string}
     *
     * @throws BusinessRuleException
     */
    private function assertBalanced(array $lines): array
    {
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($lines as $line) {
            $totalDebit = Decimal::add($totalDebit, $line['debit'], Decimal::SCALE_MONEY);
            $totalCredit = Decimal::add($totalCredit, $line['credit'], Decimal::SCALE_MONEY);
        }

        if (Decimal::cmp($totalDebit, $totalCredit) !== 0) {
            $debitTrimmed = Decimal::trim($totalDebit);
            $creditTrimmed = Decimal::trim($totalCredit);
            throw new BusinessRuleException("Total debit ({$debitTrimmed}) harus sama dengan total kredit ({$creditTrimmed}).", 422, 'lines');
        }

        if (Decimal::isZero($totalDebit)) {
            throw new BusinessRuleException('Total jurnal harus lebih besar dari nol.', 422, 'lines');
        }

        return [$totalDebit, $totalCredit];
    }

    /**
     * Write lines and record them in the audit trail, since they have no model audit events of their own.
     *
     * @param  list<array{account_id: int, debit: string, credit: string, description: string|null}>  $lines
     */
    private function writeLines(Journal $journal, array $lines): void
    {
        $lineNo = 1;

        foreach ($lines as $line) {
            JournalLine::create([
                'journal_id' => $journal->id,
                'line_no' => $lineNo++,
                'account_id' => $line['account_id'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => $line['description'],
            ]);
        }

        AuditLogger::record(
            event: 'journal.lines_saved',
            auditableType: $journal->getMorphClass(),
            auditableId: (int) $journal->id,
            new: ['lines' => $lines],
            strict: true
        );
    }
}
