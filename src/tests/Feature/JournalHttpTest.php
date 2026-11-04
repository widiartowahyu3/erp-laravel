<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\JournalStatus;
use App\Models\Journal;
use App\Services\Accounting\JournalService;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\GoodsReceiptService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AccountingFixtures;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    config(['inertia.testing.ensure_pages_exist' => false]);
    (new ChartOfAccountSeeder)->run();
    $this->cashId = (int) DB::table('chart_of_accounts')->where('code', '1101')->value('id');
    $this->capitalId = (int) DB::table('chart_of_accounts')->where('code', '3101')->value('id');
    $this->payload = [
        'journal_date' => '2026-11-04',
        'description' => 'Setoran modal',
        'lines' => [
            ['account_id' => $this->cashId, 'debit' => '500.00', 'credit' => ''],
            ['account_id' => $this->capitalId, 'debit' => '', 'credit' => '500.00'],
        ],
    ];
});

/**
 * @param  array{journal_date: string, description: string, lines: list<array<string, mixed>>}  $payload
 */
function draftJournal(array $payload): Journal
{
    // The form sends blank for the empty side; JournalRequest normalizes that before the service sees it
    $payload['lines'] = array_map(fn (array $line): array => [
        'account_id' => $line['account_id'],
        'debit' => $line['debit'] === '' ? '0' : $line['debit'],
        'credit' => $line['credit'] === '' ? '0' : $line['credit'],
    ], $payload['lines']);

    return (new JournalService)->createDraft($payload);
}

test('every journal route answers 403 without its permission and works with it', function (string $method, string $uriTemplate, string $permission, int $allowed): void {
    $journal = draftJournal($this->payload);
    if ($uriTemplate === '/accounting/journals/{id}/reverse') {
        $journal = (new JournalService)->post($journal);
    }
    $uri = str_replace('{id}', (string) $journal->id, $uriTemplate);
    $body = match (true) {
        str_ends_with($uri, '/void'), str_ends_with($uri, '/reverse') => ['reason' => 'Koreksi'],
        $method !== 'get' && ! str_ends_with($uri, '/post') => $this->payload,
        default => [],
    };

    $this->actingAs(userWithPermissions(['dashboard.none']))->{$method}($uri, $body)->assertStatus(403);
    $this->flushSession();

    $this->actingAs(userWithPermissions([$permission]))->{$method}($uri, $body)->assertStatus($allowed);
})->with([
    'index' => ['get', '/accounting/journals', 'journals.view', 200],
    'create' => ['get', '/accounting/journals/create', 'journals.create', 200],
    'store' => ['post', '/accounting/journals', 'journals.create', 302],
    'show' => ['get', '/accounting/journals/{id}', 'journals.view', 200],
    'edit' => ['get', '/accounting/journals/{id}/edit', 'journals.update', 200],
    'update' => ['put', '/accounting/journals/{id}', 'journals.update', 302],
    'post' => ['post', '/accounting/journals/{id}/post', 'journals.post', 302],
    'reverse' => ['post', '/accounting/journals/{id}/reverse', 'journals.reverse', 302],
    'void' => ['post', '/accounting/journals/{id}/void', 'journals.void', 302],
]);

test('finance role can reverse journals; roles without the permission cannot', function (): void {
    expect(RoleSeeder::roleDefinitions()['finance']['permissions'])->toContain('journals.reverse')
        ->and(RoleSeeder::roleDefinitions()['super_admin']['permissions'])->toContain('journals.reverse')
        ->and(RoleSeeder::roleDefinitions()['admin']['permissions'])->not->toContain('journals.reverse')
        ->and(RoleSeeder::roleDefinitions()['viewer']['permissions'])->not->toContain('journals.reverse');
});

test('unknown journal answers 404', function (): void {
    $this->actingAs(userWithPermissions(['journals.view']))->get('/accounting/journals/99999999')->assertStatus(404);
});

test('store validates input with field errors and creates nothing on failure', function (array $overrides, string $field): void {
    $before = Journal::count();

    $this->actingAs(userWithPermissions(['journals.create']))
        ->from('/accounting/journals/create')
        ->post('/accounting/journals', array_merge($this->payload, $overrides))
        ->assertRedirect('/accounting/journals/create')
        ->assertSessionHasErrors($field);

    expect(Journal::count())->toBe($before);
})->with([
    'no date' => [['journal_date' => ''], 'journal_date'],
    'bad date' => [['journal_date' => '04-11-2026'], 'journal_date'],
    'no description' => [['description' => ''], 'description'],
    'long description' => [['description' => str_repeat('a', 256)], 'description'],
    'no lines' => [['lines' => []], 'lines'],
    'one line' => [['lines' => [['account_id' => 1, 'debit' => '1', 'credit' => '']]], 'lines'],
]);

test('store rejects unbalanced lines through the service on the lines field', function (): void {
    $payload = $this->payload;
    $payload['lines'][1]['credit'] = '499.99';

    $this->withHeader('X-Inertia', 'true')
        ->actingAs(userWithPermissions(['journals.create']))
        ->from('/accounting/journals/create')
        ->post('/accounting/journals', $payload)
        ->assertSessionHasErrors('lines');
});

test('store ignores a source sent by the client', function (): void {
    $this->actingAs(userWithPermissions(['journals.create']))
        ->post('/accounting/journals', $this->payload + ['source_type' => 'goods_receipt', 'source_id' => 1, 'status' => 'posted'])
        ->assertSessionHasNoErrors();

    $journal = Journal::query()->latest('id')->firstOrFail();

    expect($journal->source_type)->toBeNull()
        ->and($journal->status)->toBe(JournalStatus::DRAFT);
});

test('index exposes rows, labels and filters; source links resolve for automatic journals', function (): void {
    $gr = (new GoodsReceiptService(new StockService))->post(
        AccountingFixtures::draftReceipt([['product' => TestDataFactory::createProduct(), 'quantity' => '1.0000', 'cost' => '10.0000']])
    );
    $manual = draftJournal($this->payload);

    $this->actingAs(userWithPermissions(['journals.view']))
        ->get('/accounting/journals?filter[source_type]=goods_receipt')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounting/Journals/Index')
            ->has('statuses', 3)
            ->has('sources', 3)
            ->has('items.data', 1)
            ->where('items.data.0.sourceLabel', 'Penerimaan Barang')
            ->where('items.data.0.sourceUrl', route('purchasing.goods-receipts.show', $gr->id))
            ->where('items.data.0.isReversal', false)
            ->where('items.data.0.isReversed', false)
        );

    $this->flushSession();

    $this->actingAs(userWithPermissions(['journals.view']))
        ->get('/accounting/journals?filter[source_type]=manual&search='.urlencode($manual->number))
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.id', $manual->id)
            ->where('items.data.0.sourceLabel', null)
            ->where('items.data.0.sourceUrl', null)
        );
});

test('index query count does not grow with the number of journals', function (): void {
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/accounting/journals')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $this->actingAs(userWithPermissions(['journals.view']));
    draftJournal($this->payload);
    $countQueries(); // the first request also loads the user's permissions
    $withOne = $countQueries();

    foreach (range(1, 5) as $ignored) {
        draftJournal($this->payload);
    }

    expect($countQueries())->toBe($withOne);
});

test('show exposes lines with accounts, links and the reversal pair', function (): void {
    $service = new JournalService;
    $original = $service->post(draftJournal($this->payload));
    $reversal = $service->reverse($original, 'Salah akun');

    $this->actingAs(userWithPermissions(['journals.view']))
        ->get("/accounting/journals/{$original->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounting/Journals/Show')
            ->where('isAutomatic', false)
            ->where('sourceUrl', null)
            ->where('item.number', $original->number)
            ->where('item.total_debit', '500.00')
            ->has('item.lines', 2)
            ->where('item.lines.0.account.code', '1101')
            ->where('item.reversal.id', $reversal->id)
        );

    $this->flushSession();

    $this->actingAs(userWithPermissions(['journals.view']))
        ->get("/accounting/journals/{$reversal->id}")
        ->assertInertia(fn (Assert $page) => $page->where('item.reversal_of.id', $original->id));
});

test('reverse answers with the reason error, then creates the reversal and opens it', function (): void {
    $journal = (new JournalService)->post(draftJournal($this->payload));
    $user = userWithPermissions(['journals.reverse']);

    $this->actingAs($user)->from("/accounting/journals/{$journal->id}")
        ->post("/accounting/journals/{$journal->id}/reverse", ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->actingAs($user)->post("/accounting/journals/{$journal->id}/reverse", ['reason' => str_repeat('a', 201)])
        ->assertSessionHasErrors('reason');

    $response = $this->actingAs($user)->post("/accounting/journals/{$journal->id}/reverse", ['reason' => 'Salah akun']);

    $reversal = Journal::where('reversal_of_id', $journal->id)->firstOrFail();
    $response->assertRedirect("/accounting/journals/{$reversal->id}");
});

test('editing a posted journal redirects to its detail page and updating it is rejected', function (): void {
    $journal = (new JournalService)->post(draftJournal($this->payload));
    $user = userWithPermissions(['journals.update']);

    $this->actingAs($user)->get("/accounting/journals/{$journal->id}/edit")
        ->assertRedirect("/accounting/journals/{$journal->id}");

    $this->withHeader('X-Inertia', 'true')->actingAs($user)->put("/accounting/journals/{$journal->id}", $this->payload)
        ->assertSessionHasErrors('status');
});

test('automatic journals cannot be reversed or voided directly over http', function (): void {
    $gr = (new GoodsReceiptService(new StockService))->post(
        AccountingFixtures::draftReceipt([['product' => TestDataFactory::createProduct(), 'quantity' => '1.0000', 'cost' => '10.0000']])
    );
    $journal = Journal::where('source_type', 'goods_receipt')->where('source_id', $gr->id)->firstOrFail();
    $user = userWithPermissions(['journals.reverse', 'journals.void', 'journals.view']);

    $this->withHeader('X-Inertia', 'true');

    $this->actingAs($user)->post("/accounting/journals/{$journal->id}/reverse", ['reason' => 'Langsung'])
        ->assertSessionHasErrors('status');
    $this->actingAs($user)->post("/accounting/journals/{$journal->id}/void", ['reason' => 'Langsung'])
        ->assertSessionHasErrors('status');

    expect(Journal::where('reversal_of_id', $journal->id)->count())->toBe(0);

    $this->flushHeaders();
    $this->actingAs($user)->get("/accounting/journals/{$journal->id}")
        ->assertInertia(fn (Assert $page) => $page->where('isAutomatic', true));
});
