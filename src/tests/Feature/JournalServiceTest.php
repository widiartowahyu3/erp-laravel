<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\JournalStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Journal;
use App\Models\User;
use App\Services\Accounting\JournalService;
use App\Support\Accounting\JournalDraft;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
    $this->service = new JournalService;
    $this->cashId = (int) DB::table('chart_of_accounts')->where('code', '1101')->value('id');
    $this->capitalId = (int) DB::table('chart_of_accounts')->where('code', '3101')->value('id');
});

/**
 * @return array{journal_date: string, description: string, lines: list<array<string, mixed>>}
 */
function manualJournalData(int $debitAccountId, int $creditAccountId, string $amount = '100.00'): array
{
    return [
        'journal_date' => '2026-11-04',
        'description' => 'Setoran modal',
        'lines' => [
            ['account_id' => $debitAccountId, 'debit' => $amount, 'credit' => '0', 'description' => 'Kas'],
            ['account_id' => $creditAccountId, 'debit' => '0', 'credit' => $amount, 'description' => 'Modal'],
        ],
    ];
}

function expectJournalRule(callable $action, int $code, string $field): void
{
    try {
        $action();
        test()->fail('Expected BusinessRuleException was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe($code)->and($e->field)->toBe($field);
    }
}

test('createDraft stores a numbered draft with exact totals and audited lines', function (): void {
    $journal = $this->service->createDraft(manualJournalData($this->cashId, $this->capitalId, '1234.56'));

    expect($journal->status)->toBe(JournalStatus::DRAFT)
        ->and($journal->number)->toMatch('#^JV/202611/\d{5}$#')
        ->and($journal->total_debit)->toBe('1234.56')
        ->and($journal->total_credit)->toBe('1234.56')
        ->and($journal->source_type)->toBeNull()
        ->and($journal->lines)->toHaveCount(2)
        ->and($journal->lines[0]->line_no)->toBe(1)
        ->and($journal->lines[0]->debit)->toBe('1234.56')
        ->and($journal->lines[1]->credit)->toBe('1234.56');

    expect(DB::table('audit_logs')->where('auditable_id', $journal->id)->where('event', 'journal.created')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('auditable_id', $journal->id)->where('event', 'journal.lines_saved')->exists())->toBeTrue();
});

test('money totals are exact without float drift', function (): void {
    $data = manualJournalData($this->cashId, $this->capitalId);
    $data['lines'] = [
        ['account_id' => $this->cashId, 'debit' => '0.10', 'credit' => '0'],
        ['account_id' => $this->cashId, 'debit' => '0.20', 'credit' => '0'],
        ['account_id' => $this->capitalId, 'debit' => '0', 'credit' => '0.30'],
    ];

    expect($this->service->createDraft($data)->total_debit)->toBe('0.30');
});

test('createDraft rejects an unbalanced journal on lines', function (): void {
    $data = manualJournalData($this->cashId, $this->capitalId);
    $data['lines'][1]['credit'] = '99.99';

    expectJournalRule(fn () => $this->service->createDraft($data), 422, 'lines');
});

test('createDraft rejects fewer than two lines', function (): void {
    $data = manualJournalData($this->cashId, $this->capitalId);
    $data['lines'] = [$data['lines'][0]];

    expectJournalRule(fn () => $this->service->createDraft($data), 422, 'lines');
});

test('createDraft rejects a line with both debit and credit and a zero line', function (array $line): void {
    $data = manualJournalData($this->cashId, $this->capitalId);
    $data['lines'][] = ['account_id' => $this->cashId] + $line;

    expectJournalRule(fn () => $this->service->createDraft($data), 422, 'lines');
})->with([
    'both sides' => [['debit' => '5.00', 'credit' => '5.00']],
    'zero' => [['debit' => '0', 'credit' => '0']],
]);

test('createDraft rejects invalid money values', function (mixed $amount): void {
    $data = manualJournalData($this->cashId, $this->capitalId);
    $data['lines'][0]['debit'] = $amount;

    expectJournalRule(fn () => $this->service->createDraft($data), 422, 'lines');
})->with([
    'float' => [100.5],
    'three decimals' => ['100.123'],
    'negative' => ['-100.00'],
    'text' => ['seratus'],
    'scientific' => ['1e3'],
]);

test('createDraft rejects inactive, header and missing accounts; active control passes', function (): void {
    $inactiveId = TestDataFactory::createAccount(['is_active' => false]);
    $headerId = (int) DB::table('chart_of_accounts')->where('code', '1000')->value('id');

    foreach ([$inactiveId, $headerId, 99999999] as $accountId) {
        expectJournalRule(
            fn () => $this->service->createDraft(manualJournalData($accountId, $this->capitalId)),
            422,
            'lines'
        );
    }

    DB::table('chart_of_accounts')->where('id', $inactiveId)->update(['is_active' => true]);

    expect($this->service->createDraft(manualJournalData($inactiveId, $this->capitalId))->status)->toBe(JournalStatus::DRAFT);
});

test('manual journal with a source is rejected', function (): void {
    $data = manualJournalData($this->cashId, $this->capitalId);
    $data['source_type'] = 'goods_receipt';

    expectJournalRule(fn () => $this->service->createDraft($data), 422, 'source_type');
});

test('updateDraft replaces lines and totals; only drafts can change', function (): void {
    $journal = $this->service->createDraft(manualJournalData($this->cashId, $this->capitalId));

    $updated = $this->service->updateDraft($journal, manualJournalData($this->cashId, $this->capitalId, '250.00'));

    expect($updated->total_debit)->toBe('250.00')
        ->and($updated->lines)->toHaveCount(2)
        ->and($updated->number)->toBe($journal->number);

    $posted = $this->service->post($updated);

    expect(fn () => $this->service->updateDraft($posted, manualJournalData($this->cashId, $this->capitalId)))
        ->toThrow(DocumentNotEditableException::class);
});

test('voidDraft requires a reason of 1 to 255 characters and only applies to drafts', function (): void {
    $journal = $this->service->createDraft(manualJournalData($this->cashId, $this->capitalId));

    expectJournalRule(fn () => $this->service->voidDraft($journal, '   '), 422, 'reason');
    expectJournalRule(fn () => $this->service->voidDraft($journal, str_repeat('a', 256)), 422, 'reason');

    $voided = $this->service->voidDraft($journal, 'Salah input');

    expect($voided->status)->toBe(JournalStatus::VOID)
        ->and($voided->void_reason)->toBe('Salah input')
        ->and($voided->voided_by)->toBe(Auth::id())
        ->and($voided->voided_at)->not->toBeNull();

    $posted = $this->service->post($this->service->createDraft(manualJournalData($this->cashId, $this->capitalId)));

    expect(fn () => $this->service->voidDraft($posted, 'Batal'))->toThrow(DocumentNotEditableException::class);
});

test('post records who and when and cannot run twice', function (): void {
    $posted = $this->service->post($this->service->createDraft(manualJournalData($this->cashId, $this->capitalId)));

    expect($posted->status)->toBe(JournalStatus::POSTED)
        ->and($posted->posted_by)->toBe(Auth::id())
        ->and($posted->posted_at)->not->toBeNull();

    expect(fn () => $this->service->post($posted))->toThrow(InvalidStateTransitionException::class);
});

test('post rejects a draft whose account was deactivated after it was created', function (): void {
    $accountId = TestDataFactory::createAccount();
    $journal = $this->service->createDraft(manualJournalData($accountId, $this->capitalId));

    DB::table('chart_of_accounts')->where('id', $accountId)->update(['is_active' => false]);

    expectJournalRule(fn () => $this->service->post($journal), 422, 'lines');
    expect($journal->fresh()->status)->toBe(JournalStatus::DRAFT);
});

test('reverse swaps debit and credit exactly, links the original and uses today', function (): void {
    CarbonImmutable::setTestNow('2026-12-15 10:00:00');
    $original = $this->service->post($this->service->createDraft(manualJournalData($this->cashId, $this->capitalId, '333.33')));

    $reversal = $this->service->reverse($original, 'Salah akun');

    expect($reversal->status)->toBe(JournalStatus::POSTED)
        ->and($reversal->reversal_of_id)->toBe($original->id)
        ->and($reversal->journal_date->toDateString())->toBe('2026-12-15')
        ->and($reversal->description)->toBe("Pembalik {$original->number}: Salah akun")
        ->and($reversal->total_debit)->toBe('333.33')
        ->and($reversal->lines[0]->account_id)->toBe($this->cashId)
        ->and($reversal->lines[0]->debit)->toBe('0.00')
        ->and($reversal->lines[0]->credit)->toBe('333.33')
        ->and($reversal->lines[1]->account_id)->toBe($this->capitalId)
        ->and($reversal->lines[1]->debit)->toBe('333.33')
        ->and($reversal->lines[1]->credit)->toBe('0.00')
        ->and($original->fresh()->status)->toBe(JournalStatus::POSTED);

    CarbonImmutable::setTestNow();
});

test('reverse requires a reason of 1 to 200 characters', function (): void {
    $original = $this->service->post($this->service->createDraft(manualJournalData($this->cashId, $this->capitalId)));

    expectJournalRule(fn () => $this->service->reverse($original, ' '), 422, 'reason');
    expectJournalRule(fn () => $this->service->reverse($original, str_repeat('a', 201)), 422, 'reason');
    expect(Journal::where('reversal_of_id', $original->id)->count())->toBe(0);
});

test('reverse rejects second reversals, reversals, drafts, voids and automatic journals with 409', function (): void {
    $original = $this->service->post($this->service->createDraft(manualJournalData($this->cashId, $this->capitalId)));
    $reversal = $this->service->reverse($original, 'Pertama');

    expectJournalRule(fn () => $this->service->reverse($original, 'Kedua'), 409, 'status');
    expectJournalRule(fn () => $this->service->reverse($reversal, 'Balik lagi'), 409, 'status');

    $draft = $this->service->createDraft(manualJournalData($this->cashId, $this->capitalId));
    expectJournalRule(fn () => $this->service->reverse($draft, 'Draf'), 409, 'status');

    $voided = $this->service->voidDraft($this->service->createDraft(manualJournalData($this->cashId, $this->capitalId)), 'Batal');
    expectJournalRule(fn () => $this->service->reverse($voided, 'Batal'), 409, 'status');

    $automatic = DB::transaction(fn () => $this->service->postAutomatic(
        (new JournalDraft('Otomatis'))->debit('inventory', '10.00')->credit('grir', '10.00'),
        'goods_receipt', 501, CarbonImmutable::parse('2026-11-04')
    ));
    expectJournalRule(fn () => $this->service->reverse($automatic, 'Langsung'), 409, 'status');
    expectJournalRule(fn () => $this->service->voidDraft($automatic, 'Langsung'), 409, 'status');
});

test('postAutomatic posts immediately with the source and date and is idempotent per source', function (): void {
    $draft = (new JournalDraft('Penerimaan GR/1'))->debit('inventory', '10500.00')->credit('grir', '10500.00');

    $first = DB::transaction(fn () => $this->service->postAutomatic($draft, 'goods_receipt', 503, CarbonImmutable::parse('2026-11-01')));
    $second = DB::transaction(fn () => $this->service->postAutomatic($draft, 'goods_receipt', 503, CarbonImmutable::parse('2026-11-01')));

    expect($first->status)->toBe(JournalStatus::POSTED)
        ->and($first->source_type)->toBe('goods_receipt')
        ->and($first->source_id)->toBe(503)
        ->and($first->journal_date->toDateString())->toBe('2026-11-01')
        ->and($first->total_debit)->toBe('10500.00')
        ->and($first->posted_by)->toBe(Auth::id())
        ->and($second->id)->toBe($first->id)
        ->and(Journal::where('source_type', 'goods_receipt')->where('source_id', 503)->count())->toBe(1);
});

test('postAutomatic creates no journal for zero values and records the skip in audit', function (): void {
    $draft = (new JournalDraft('Nol'))->debit('inventory', '0.00')->credit('grir', '0.00');

    $journal = DB::transaction(fn () => $this->service->postAutomatic($draft, 'goods_receipt', 504, CarbonImmutable::parse('2026-11-01')));

    expect($journal)->toBeNull()
        ->and(Journal::where('source_id', 504)->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('event', 'journal.skipped')->where('auditable_id', 504)->exists())->toBeTrue();
});

test('postAutomatic rejects an unbalanced draft and a blocked account, leaving nothing behind', function (): void {
    $unbalanced = (new JournalDraft('Tidak seimbang'))->debit('inventory', '10.00')->credit('grir', '9.00');

    expectJournalRule(
        fn () => DB::transaction(fn () => $this->service->postAutomatic($unbalanced, 'goods_receipt', 505, CarbonImmutable::parse('2026-11-01'))),
        422,
        'lines'
    );

    DB::table('chart_of_accounts')->where('code', '2103')->update(['is_active' => false]);
    $balanced = (new JournalDraft('Akun nonaktif'))->debit('inventory', '10.00')->credit('grir', '10.00');

    expect(fn () => DB::transaction(fn () => (new JournalService)->postAutomatic($balanced, 'goods_receipt', 505, CarbonImmutable::parse('2026-11-01'))))
        ->toThrow(BusinessRuleException::class, '2103');

    expect(Journal::where('source_id', 505)->count())->toBe(0);
});

test('reverseAutomatic mirrors the original once, keeps the source, and skips documents without a journal', function (): void {
    $draft = (new JournalDraft('Penerimaan'))->debit('inventory', '75.50')->credit('grir', '75.50');
    $original = DB::transaction(fn () => $this->service->postAutomatic($draft, 'goods_receipt', 506, CarbonImmutable::parse('2026-11-01')));

    $reversal = DB::transaction(fn () => $this->service->reverseAutomatic('goods_receipt', 506, CarbonImmutable::parse('2026-11-04')));
    $again = DB::transaction(fn () => $this->service->reverseAutomatic('goods_receipt', 506, CarbonImmutable::parse('2026-11-05')));

    expect($reversal->reversal_of_id)->toBe($original->id)
        ->and($reversal->source_type)->toBe('goods_receipt')
        ->and($reversal->source_id)->toBe(506)
        ->and($reversal->journal_date->toDateString())->toBe('2026-11-04')
        ->and($reversal->lines[0]->credit)->toBe('75.50')
        ->and($reversal->lines[1]->debit)->toBe('75.50')
        ->and($again->id)->toBe($reversal->id)
        ->and(Journal::where('source_id', 506)->count())->toBe(2);

    $none = DB::transaction(fn () => $this->service->reverseAutomatic('goods_receipt', 507, CarbonImmutable::parse('2026-11-04')));

    expect($none)->toBeNull()
        ->and(DB::table('audit_logs')->where('event', 'journal.reversal_skipped')->where('auditable_id', 507)->exists())->toBeTrue();
});

test('audit failure rolls back the journal completely; valid user control succeeds', function (): void {
    $fakeUser = new User;
    $fakeUser->id = 999999999;
    Auth::setUser($fakeUser);

    $before = Journal::count();
    $thrown = false;

    try {
        $this->service->createDraft(manualJournalData($this->cashId, $this->capitalId));
    } catch (\Throwable) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue()->and(Journal::count())->toBe($before);

    Auth::setUser(User::factory()->create());

    expect($this->service->createDraft(manualJournalData($this->cashId, $this->capitalId))->status)->toBe(JournalStatus::DRAFT)
        ->and(Journal::count())->toBe($before + 1);
});
