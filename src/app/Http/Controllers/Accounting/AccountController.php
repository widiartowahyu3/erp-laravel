<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\RespondsToDeleteConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\AccountStoreRequest;
use App\Http\Requests\Accounting\AccountUpdateRequest;
use App\Models\ChartOfAccount;
use App\Services\Accounting\ChartOfAccountService;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountController extends Controller
{
    use RespondsToDeleteConflict;

    public function __construct(
        private readonly ChartOfAccountService $accountService
    ) {}

    public function index(Request $request): Response
    {
        $paginator = ListQuery::for(ChartOfAccount::query()->with('parent:id,code,name'))
            ->searchable(['code', 'name'])
            ->sortable(['code', 'name', 'type', 'is_active'])
            ->filterable([
                'type' => 'type',
                'is_active' => 'is_active',
            ])
            ->defaultSort('code', 'asc')
            ->paginate($request, 50);

        $levels = $this->accountService->levels();

        return Inertia::render('Accounting/Accounts/Index', [
            'items' => Paginated::from($paginator, fn (ChartOfAccount $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'normal_balance' => $account->normal_balance->value,
                'normal_balance_label' => $account->normal_balance->label(),
                'is_postable' => $account->is_postable,
                'is_active' => $account->is_active,
                'level' => $levels[$account->id] ?? 0,
            ], $request),
            'types' => $this->typeOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/Accounts/Create', [
            'parents' => $this->headerAccounts(),
            'types' => $this->typeOptions(),
            'normalBalances' => $this->normalBalanceOptions(),
        ]);
    }

    public function store(AccountStoreRequest $request): RedirectResponse
    {
        $this->accountService->create($request->validated());

        return redirect()
            ->route('accounting.accounts.index')
            ->with('success', 'Akun berhasil ditambahkan.');
    }

    public function show(ChartOfAccount $account): Response
    {
        $account->load('parent:id,code,name');

        return Inertia::render('Accounting/Accounts/Show', [
            'item' => $account,
            'isUsed' => $this->accountService->isUsedByJournals($account),
            'isMapped' => $this->accountService->isMapped($account),
        ]);
    }

    public function edit(ChartOfAccount $account): Response
    {
        return Inertia::render('Accounting/Accounts/Edit', [
            'item' => $account,
            'isUsed' => $this->accountService->isUsedByJournals($account),
            'isMapped' => $this->accountService->isMapped($account),
            'parents' => $this->headerAccounts($account->id),
            'types' => $this->typeOptions(),
            'normalBalances' => $this->normalBalanceOptions(),
        ]);
    }

    public function update(AccountUpdateRequest $request, ChartOfAccount $account): RedirectResponse
    {
        $this->accountService->update($account, $request->validated());

        return redirect()
            ->route('accounting.accounts.index')
            ->with('success', 'Akun berhasil diperbarui.');
    }

    public function destroy(Request $request, ChartOfAccount $account): JsonResponse|RedirectResponse
    {
        try {
            $this->accountService->delete($account);

            return back()->with('success', 'Akun berhasil dihapus.');
        } catch (BusinessRuleException $e) {
            return $this->respondToDeleteConflict($request, $e);
        }
    }

    /**
     * @return Collection<int, ChartOfAccount>
     */
    private function headerAccounts(?int $exceptId = null): Collection
    {
        return ChartOfAccount::query()
            ->select(['id', 'code', 'name'])
            ->where('is_postable', false)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->orderBy('code')
            ->get();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(): array
    {
        return array_map(fn (AccountType $type): array => ['value' => $type->value, 'label' => $type->label()], AccountType::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function normalBalanceOptions(): array
    {
        return array_map(fn (NormalBalance $balance): array => ['value' => $balance->value, 'label' => $balance->label()], NormalBalance::cases());
    }
}
