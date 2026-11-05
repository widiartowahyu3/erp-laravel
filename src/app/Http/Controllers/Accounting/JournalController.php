<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Enums\JournalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\JournalRequest;
use App\Http\Requests\Accounting\JournalReverseRequest;
use App\Http\Requests\Accounting\JournalVoidRequest;
use App\Models\ChartOfAccount;
use App\Models\Journal;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\JournalSourceResolver;
use App\Services\Reports\ListExports;
use App\Support\Documents\DocumentPrintProps;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class JournalController extends Controller
{
    public function __construct(
        private readonly JournalService $journalService
    ) {}

    public function index(Request $request, ListExports $exports): Response|StreamedResponse
    {
        $list = ListQuery::for(Journal::query()->withExists('reversal'))
            ->searchable(['number', 'description'])
            ->sortable(['id', 'number', 'journal_date', 'status', 'total_debit', 'created_at'])
            ->filterable([
                'status' => 'status',
                'source_type' => function (Builder $query, mixed $value): void {
                    if ($value === 'manual') {
                        $query->whereNull('source_type');
                    } else {
                        $query->where('source_type', (string) $value);
                    }
                },
                'date_from' => function (Builder $query, mixed $value): void {
                    $query->where('journal_date', '>=', $value);
                },
                'date_to' => function (Builder $query, mixed $value): void {
                    $query->where('journal_date', '<=', $value);
                },
            ])
            ->defaultSort('journal_date', 'desc');

        if (ListExports::requested($request)) {
            return $exports->journals($list->apply($request));
        }

        $paginator = $list->paginate($request, 25);

        return Inertia::render('Accounting/Journals/Index', [
            'items' => Paginated::from($paginator, fn (Journal $journal): array => [
                'id' => $journal->id,
                'number' => $journal->number,
                'journal_date' => $journal->journal_date,
                'description' => $journal->description,
                'status' => $journal->status->value,
                'total_debit' => $journal->total_debit,
                'sourceLabel' => JournalSourceResolver::label($journal->source_type),
                'sourceUrl' => JournalSourceResolver::url($journal->source_type, $journal->source_id),
                'isReversal' => $journal->reversal_of_id !== null,
                'isReversed' => (bool) $journal->getAttribute('reversal_exists'),
            ], $request),
            'statuses' => array_map(fn (JournalStatus $status): string => $status->value, JournalStatus::cases()),
            'sources' => JournalSourceResolver::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/Journals/Create', [
            'accounts' => $this->postableAccounts(),
        ]);
    }

    public function store(JournalRequest $request): RedirectResponse
    {
        $journal = $this->journalService->createDraft($request->validated());

        return redirect()
            ->route('accounting.journals.show', $journal)
            ->with('success', 'Draf jurnal berhasil dibuat.');
    }

    public function show(Journal $journal): Response
    {
        $journal->load([
            'lines.account:id,code,name',
            'reversalOf:id,number',
            'reversal:id,number,reversal_of_id',
            'creator:id,name',
            'poster:id,name',
            'voider:id,name',
        ]);

        return Inertia::render('Accounting/Journals/Show', [
            'item' => $journal,
            'sourceLabel' => JournalSourceResolver::label($journal->source_type),
            'sourceUrl' => JournalSourceResolver::url($journal->source_type, $journal->source_id),
            'isAutomatic' => $journal->source_type !== null,
        ]);
    }

    public function print(Journal $journal): Response
    {
        return Inertia::render('Accounting/Journals/Print', [
            'item' => DocumentPrintProps::journal($journal),
        ]);
    }

    public function edit(Journal $journal): Response|RedirectResponse
    {
        if (! $journal->isEditable()) {
            return redirect()
                ->route('accounting.journals.show', $journal)
                ->with('error', 'Hanya draf jurnal yang dapat diubah.');
        }

        $journal->load('lines');

        return Inertia::render('Accounting/Journals/Edit', [
            'item' => $journal,
            'accounts' => $this->postableAccounts(),
        ]);
    }

    public function update(JournalRequest $request, Journal $journal): RedirectResponse
    {
        $updated = $this->journalService->updateDraft($journal, $request->validated());

        return redirect()
            ->route('accounting.journals.show', $updated)
            ->with('success', 'Draf jurnal berhasil diperbarui.');
    }

    public function post(Journal $journal): RedirectResponse
    {
        $this->journalService->post($journal);

        return back()->with('success', 'Jurnal berhasil diposting.');
    }

    public function reverse(JournalReverseRequest $request, Journal $journal): RedirectResponse
    {
        $reversal = $this->journalService->reverse($journal, (string) $request->input('reason'));

        return redirect()
            ->route('accounting.journals.show', $reversal)
            ->with('success', 'Jurnal pembalik berhasil dibuat.');
    }

    public function void(JournalVoidRequest $request, Journal $journal): RedirectResponse
    {
        $this->journalService->voidDraft($journal, (string) $request->input('reason'));

        return back()->with('success', 'Draf jurnal berhasil dibatalkan.');
    }

    /**
     * @return Collection<int, ChartOfAccount>
     */
    private function postableAccounts(): Collection
    {
        return ChartOfAccount::query()
            ->select(['id', 'code', 'name'])
            ->where('is_active', true)
            ->where('is_postable', true)
            ->orderBy('code')
            ->get();
    }
}
