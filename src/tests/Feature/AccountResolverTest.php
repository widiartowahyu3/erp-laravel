<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Services\Accounting\AccountResolver;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\TestDataFactory;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
});

test('resolver returns the id of every mapped account after seeding', function (): void {
    $resolver = new AccountResolver;

    foreach (config('accounting.accounts') as $key => $code) {
        expect($resolver->id($key))->toBe((int) DB::table('chart_of_accounts')->where('code', $code)->value('id'));
    }

    foreach (config('accounting.cash_bank') as $code) {
        expect($resolver->idByCode($code))->toBe((int) DB::table('chart_of_accounts')->where('code', $code)->value('id'));
    }
});

test('resolver maps vat, discount and adjustment keys to the seeded account codes', function (): void {
    expect(config('accounting.accounts'))->toMatchArray([
        'inventory' => '1301',
        'receivable' => '1201',
        'payable' => '2101',
        'vat_output' => '2201',
        'vat_input' => '1401',
        'grir' => '2103',
        'sales' => '4101',
        'sales_discount' => '4201',
        'cogs' => '5101',
        'inventory_adjustment' => '5201',
    ]);
});

test('resolver rejects a missing account with 422 naming the code', function (): void {
    $resolver = new AccountResolver;

    try {
        $resolver->idByCode('9999');
        test()->fail('Expected BusinessRuleException was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(422)->and($e->getMessage())->toContain('9999');
    }
});

test('resolver rejects an inactive account with 422 naming the code; active control passes', function (): void {
    $code = 'ACC-'.bin2hex(random_bytes(3));
    $accountId = TestDataFactory::createAccount(['code' => $code, 'is_active' => false]);
    $resolver = new AccountResolver;

    try {
        $resolver->idByCode($code);
        test()->fail('Expected BusinessRuleException was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(422)->and($e->getMessage())->toContain($code);
    }

    DB::table('chart_of_accounts')->where('id', $accountId)->update(['is_active' => true]);

    expect((new AccountResolver)->idByCode($code))->toBe($accountId);
});

test('resolver rejects a header account with 422 naming the code', function (): void {
    try {
        (new AccountResolver)->idByCode('1000');
        test()->fail('Expected BusinessRuleException was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(422)->and($e->getMessage())->toContain('1000');
    }
});

test('resolver rejects a soft deleted account with 422 naming the code', function (): void {
    $code = 'ACC-'.bin2hex(random_bytes(3));
    TestDataFactory::createAccount(['code' => $code, 'deleted_at' => now()]);

    expect(fn () => (new AccountResolver)->idByCode($code))
        ->toThrow(BusinessRuleException::class, $code);
});

test('resolver throws a programmer error for an unknown key', function (): void {
    expect(fn () => (new AccountResolver)->id('tidak_dikenal'))
        ->toThrow(LogicException::class, 'tidak_dikenal');
});

test('resolver caches lookups on the instance only, so a new instance sees fresh state', function (): void {
    $resolver = new AccountResolver;
    $id = $resolver->id('inventory');

    DB::table('chart_of_accounts')->where('code', '1301')->update(['is_active' => false]);

    expect($resolver->id('inventory'))->toBe($id)
        ->and(fn () => (new AccountResolver)->id('inventory'))->toThrow(BusinessRuleException::class, '1301');
});

test('chart of accounts seeder is idempotent with 21 accounts, 6 headers and 15 details', function (): void {
    (new ChartOfAccountSeeder)->run();

    expect(DB::table('chart_of_accounts')->whereIn('code', array_merge(
        ['1000', '2000', '3000', '4000', '5000', '6000'],
        array_values(config('accounting.accounts'))
    ))->count())->toBe(16)
        ->and(DB::table('chart_of_accounts')->where('is_postable', false)->whereNull('parent_id')->count())->toBe(6)
        ->and(DB::table('chart_of_accounts')->where('code', 'NOT LIKE', 'A%')->where('code', 'NOT LIKE', 'ACC-%')->count())->toBe(21);

    $grir = DB::table('chart_of_accounts')->where('code', '2103')->first();
    $liabilityHeaderId = DB::table('chart_of_accounts')->where('code', '2000')->value('id');

    expect($grir->name)->toBe('Barang Diterima Belum Ditagih (GR/IR)')
        ->and($grir->type)->toBe('liability')
        ->and($grir->normal_balance)->toBe('credit')
        ->and((bool) $grir->is_postable)->toBeTrue()
        ->and((bool) $grir->is_active)->toBeTrue()
        ->and($grir->parent_id)->toBe($liabilityHeaderId);
});
