<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Services\Accounting\JournalService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    config(['inertia.testing.ensure_pages_exist' => false]);
    (new ChartOfAccountSeeder)->run();
    $this->assetHeaderId = (int) DB::table('chart_of_accounts')->where('code', '1000')->value('id');
    $this->code = 'T'.random_int(10000, 99999);
    $this->payload = [
        'code' => $this->code,
        'name' => 'Akun Uji',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'parent_id' => $this->assetHeaderId,
        'is_postable' => true,
        'is_active' => true,
    ];
});

function freeAccount(int $parentId, array $overrides = []): ChartOfAccount
{
    return ChartOfAccount::findOrFail(TestDataFactory::createAccount(['parent_id' => $parentId, 'code' => 'U'.strtoupper(bin2hex(random_bytes(5)))] + $overrides));
}

function useAccountInJournal(int $accountId): void
{
    (new JournalService)->createDraft([
        'journal_date' => '2026-11-04',
        'description' => 'Pemakaian akun',
        'lines' => [
            ['account_id' => $accountId, 'debit' => '1.00', 'credit' => '0'],
            ['account_id' => (int) DB::table('chart_of_accounts')->where('code', '3101')->value('id'), 'debit' => '0', 'credit' => '1.00'],
        ],
    ]);
}

test('every account route answers 403 without its permission and works with it', function (string $method, string $uriTemplate, string $permission, int $allowed): void {
    $account = freeAccount($this->assetHeaderId);
    $uri = str_replace('{id}', (string) $account->id, $uriTemplate);
    $body = in_array($method, ['post', 'put'], true) ? $this->payload : [];

    $this->actingAs(userWithPermissions(['dashboard.none']))->{$method}($uri, $body)->assertStatus(403);
    $this->flushSession();

    $this->actingAs(userWithPermissions([$permission]))->{$method}($uri, $body)->assertStatus($allowed);
})->with([
    'index' => ['get', '/accounting/accounts', 'chart_of_accounts.view', 200],
    'create' => ['get', '/accounting/accounts/create', 'chart_of_accounts.create', 200],
    'store' => ['post', '/accounting/accounts', 'chart_of_accounts.create', 302],
    'show' => ['get', '/accounting/accounts/{id}', 'chart_of_accounts.view', 200],
    'edit' => ['get', '/accounting/accounts/{id}/edit', 'chart_of_accounts.update', 200],
    'update' => ['put', '/accounting/accounts/{id}', 'chart_of_accounts.update', 302],
    'destroy' => ['delete', '/accounting/accounts/{id}', 'chart_of_accounts.delete', 302],
]);

test('unknown account answers 404', function (): void {
    $this->actingAs(userWithPermissions(['chart_of_accounts.view']))->get('/accounting/accounts/99999999')->assertStatus(404);
});

test('index returns a tree ordered by code with levels and filters by type', function (): void {
    $this->actingAs(userWithPermissions(['chart_of_accounts.view']))
        ->get('/accounting/accounts?filter[type]=liability')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounting/Accounts/Index')
            ->has('types', 5)
            ->where('items.data.0.code', '2000')
            ->where('items.data.0.level', 0)
            ->where('items.data.0.is_postable', false)
            ->where('items.data.1.code', '2101')
            ->where('items.data.1.level', 1)
            ->where('items.data.1.type_label', 'Kewajiban')
        );
});

test('store creates an account; contra accounts may pair any type and normal balance', function (): void {
    $this->actingAs(userWithPermissions(['chart_of_accounts.create']))
        ->post('/accounting/accounts', ['type' => 'revenue', 'normal_balance' => 'debit'] + $this->payload)
        ->assertSessionHasNoErrors();

    $account = ChartOfAccount::where('code', $this->code)->firstOrFail();

    expect($account->type->value)->toBe('revenue')->and($account->normal_balance->value)->toBe('debit');
});

test('store validates input and duplicate codes', function (array $overrides, string $field): void {
    $this->actingAs(userWithPermissions(['chart_of_accounts.create']))
        ->post('/accounting/accounts', array_merge($this->payload, $overrides))
        ->assertSessionHasErrors($field);
})->with([
    'duplicate code' => [['code' => '1101'], 'code'],
    'no name' => [['name' => ''], 'name'],
    'bad type' => [['type' => 'lainnya'], 'type'],
    'bad balance' => [['normal_balance' => 'kiri'], 'normal_balance'],
    'unknown parent' => [['parent_id' => 99999999], 'parent_id'],
]);

test('parent must be a header account; header control passes', function (): void {
    $this->withHeader('X-Inertia', 'true');
    $postableId = (int) DB::table('chart_of_accounts')->where('code', '1101')->value('id');
    $user = userWithPermissions(['chart_of_accounts.create']);

    $this->actingAs($user)->post('/accounting/accounts', ['parent_id' => $postableId] + $this->payload)
        ->assertSessionHasErrors('parent_id');

    $this->actingAs($user)->post('/accounting/accounts', $this->payload)->assertSessionHasNoErrors();
});

test('parent cannot create a cycle or be the account itself', function (): void {
    $this->withHeader('X-Inertia', 'true');
    $top = freeAccount($this->assetHeaderId, ['is_postable' => false]);
    $middle = freeAccount($top->id, ['is_postable' => false]);
    $user = userWithPermissions(['chart_of_accounts.update']);

    $payload = fn (ChartOfAccount $account, int $parentId): array => [
        'code' => $account->code, 'name' => $account->name, 'type' => 'asset', 'normal_balance' => 'debit',
        'parent_id' => $parentId, 'is_postable' => false, 'is_active' => true,
    ];

    $this->actingAs($user)->put("/accounting/accounts/{$top->id}", $payload($top, $top->id))->assertSessionHasErrors('parent_id');
    $this->actingAs($user)->put("/accounting/accounts/{$top->id}", $payload($top, $middle->id))->assertSessionHasErrors('parent_id');
    $this->actingAs($user)->put("/accounting/accounts/{$middle->id}", $payload($middle, $this->assetHeaderId))->assertSessionHasNoErrors();
});

test('an account used by journal lines locks code, type, balance and postable; name stays editable', function (array $change, string $field): void {
    $this->withHeader('X-Inertia', 'true');
    $account = freeAccount($this->assetHeaderId);
    useAccountInJournal($account->id);
    $base = ['code' => $account->code, 'name' => 'Nama Baru', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $this->assetHeaderId, 'is_postable' => true, 'is_active' => true];
    $user = userWithPermissions(['chart_of_accounts.update']);

    $this->actingAs($user)->put("/accounting/accounts/{$account->id}", array_merge($base, $change))->assertSessionHasErrors($field);
    $this->actingAs($user)->put("/accounting/accounts/{$account->id}", $base)->assertSessionHasNoErrors();

    expect($account->fresh()->name)->toBe('Nama Baru');
})->with([
    'code' => [['code' => 'UBAH-123'], 'code'],
    'type' => [['type' => 'expense'], 'type'],
    'balance' => [['normal_balance' => 'credit'], 'normal_balance'],
    'postable' => [['is_postable' => false], 'is_postable'],
]);

test('an unused account can change its locked fields', function (): void {
    $account = freeAccount($this->assetHeaderId);

    $this->actingAs(userWithPermissions(['chart_of_accounts.update']))
        ->put("/accounting/accounts/{$account->id}", ['code' => 'BARU-'.random_int(100, 999), 'name' => 'X', 'type' => 'expense', 'normal_balance' => 'debit', 'parent_id' => $this->assetHeaderId, 'is_postable' => true, 'is_active' => true])
        ->assertSessionHasNoErrors();

    expect($account->fresh()->type->value)->toBe('expense');
});

test('a mapped account cannot be deactivated, renamed by code, or deleted', function (): void {
    $this->withHeader('X-Inertia', 'true');
    $account = ChartOfAccount::where('code', '1301')->firstOrFail();
    $base = ['code' => '1301', 'name' => $account->name, 'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $account->parent_id, 'is_postable' => true, 'is_active' => true];
    $user = userWithPermissions(['chart_of_accounts.update', 'chart_of_accounts.delete']);

    $this->actingAs($user)->put("/accounting/accounts/{$account->id}", ['is_active' => false] + $base)->assertSessionHasErrors('is_active');
    $this->actingAs($user)->put("/accounting/accounts/{$account->id}", ['code' => '1399'] + $base)->assertSessionHasErrors('code');
    $this->actingAs($user)->delete("/accounting/accounts/{$account->id}")->assertSessionHas('error');

    expect(ChartOfAccount::whereKey($account->id)->exists())->toBeTrue();
});

test('delete soft deletes a free account but refuses used accounts and parents', function (): void {
    $user = userWithPermissions(['chart_of_accounts.delete']);

    $free = freeAccount($this->assetHeaderId);
    $this->actingAs($user)->delete("/accounting/accounts/{$free->id}")->assertSessionHas('success');
    expect(ChartOfAccount::whereKey($free->id)->exists())->toBeFalse()
        ->and(ChartOfAccount::withTrashed()->whereKey($free->id)->exists())->toBeTrue();

    $used = freeAccount($this->assetHeaderId);
    useAccountInJournal($used->id);
    $this->actingAs($user)->delete("/accounting/accounts/{$used->id}")->assertSessionHas('error');
    expect(ChartOfAccount::whereKey($used->id)->exists())->toBeTrue();

    $parent = freeAccount($this->assetHeaderId, ['is_postable' => false]);
    freeAccount($parent->id);
    $this->actingAs($user)->delete("/accounting/accounts/{$parent->id}")->assertSessionHas('error');
    expect(ChartOfAccount::whereKey($parent->id)->exists())->toBeTrue();
});

test('account changes are audited', function (): void {
    $this->actingAs(userWithPermissions(['chart_of_accounts.create']))->post('/accounting/accounts', $this->payload);

    $account = ChartOfAccount::where('code', $this->code)->firstOrFail();

    expect(DB::table('audit_logs')->where('event', 'account.created')->where('auditable_id', $account->id)->exists())->toBeTrue();
});
