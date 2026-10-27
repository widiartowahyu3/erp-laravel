<?php

declare(strict_types=1);

use App\Http\Requests\Support\ListRequest;
use App\Support\Inertia\Paginated;
use App\Support\Query\ListQuery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\Support\Models\TestUnit;

uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('units')->delete();
});

test('ListRequest validates parameters and rejects per_page above 100', function () {
    $validData = [
        'search' => 'Box',
        'sort' => 'name',
        'direction' => 'asc',
        'page' => 2,
        'per_page' => 50,
        'filter' => ['code' => 'BX'],
    ];

    $validator = Validator::make($validData, (new ListRequest)->rules());
    expect($validator->passes())->toBeTrue();

    // per_page > 100 must fail validation
    $invalidData = array_merge($validData, ['per_page' => 101]);
    $invalidValidator = Validator::make($invalidData, (new ListRequest)->rules());
    expect($invalidValidator->fails())->toBeTrue()
        ->and($invalidValidator->errors()->has('per_page'))->toBeTrue();

    // search < 2 characters must fail validation
    $shortSearchValidator = Validator::make(['search' => 'a'], (new ListRequest)->rules());
    expect($shortSearchValidator->fails())->toBeTrue()
        ->and($shortSearchValidator->errors()->has('search'))->toBeTrue();
});

test('sort and direction containing SQL injection are safely ignored without error', function () {
    TestUnit::create(['code' => 'U1', 'name' => 'Alpha']);
    TestUnit::create(['code' => 'U2', 'name' => 'Beta']);

    $maliciousSorts = [
        'id; DROP TABLE units; --',
        'name UNION SELECT 1,2,3,4,5,6,7,8; --',
        '(SELECT sleep(5))',
        'unknown_column',
    ];

    foreach ($maliciousSorts as $badSort) {
        $request = Request::create('/test-list', 'GET', [
            'sort' => $badSort,
            'direction' => 'asc; DROP TABLE units; --',
        ]);

        $paginator = ListQuery::for(TestUnit::query())
            ->sortable(['id', 'code', 'name'])
            ->defaultSort('id', 'desc')
            ->paginate($request);

        // Fallback to default sort 'id desc' without SQL error or injection
        expect($paginator->total())->toBe(2)
            ->and($paginator->items()[0]->code)->toBe('U2')
            ->and($paginator->items()[1]->code)->toBe('U1');
    }

    // Units table still exists and intact
    expect(TestUnit::count())->toBe(2);
});

test('wildcards in search are escaped and treated literally', function () {
    TestUnit::create(['code' => 'PCT', 'name' => '10% Bonus']);
    TestUnit::create(['code' => 'STD', 'name' => '100 Bonus']);
    TestUnit::create(['code' => 'UND_1', 'name' => 'Item_1 Special']);
    TestUnit::create(['code' => 'UND_2', 'name' => 'ItemX1 Normal']);

    // 1. Search for '10%' must match ONLY '10% Bonus', NOT '100 Bonus'
    $reqPercent = Request::create('/test', 'GET', ['search' => '10%']);
    $resPercent = ListQuery::for(TestUnit::query())
        ->searchable(['name'])
        ->paginate($reqPercent);

    expect($resPercent->total())->toBe(1)
        ->and($resPercent->items()[0]->name)->toBe('10% Bonus');

    // 2. Search for 'Item_' must match ONLY 'Item_1 Special', NOT 'ItemX1 Normal'
    $reqUnderscore = Request::create('/test', 'GET', ['search' => 'Item_']);
    $resUnderscore = ListQuery::for(TestUnit::query())
        ->searchable(['name'])
        ->paginate($reqUnderscore);

    expect($resUnderscore->total())->toBe(1)
        ->and($resUnderscore->items()[0]->name)->toBe('Item_1 Special');
});

test('unrecognized filters are ignored without error and known filters are applied', function () {
    TestUnit::create(['code' => 'MTR', 'name' => 'Meter']);
    TestUnit::create(['code' => 'CM', 'name' => 'Centimeter']);

    $request = Request::create('/test', 'GET', [
        'filter' => [
            'malicious_key' => 'drop_database',
            'random_filter' => '123',
            'code' => 'MTR',
        ],
    ]);

    $paginator = ListQuery::for(TestUnit::query())
        ->filterable([
            'code' => 'code',
            'name_search' => fn ($builder, $val) => $builder->where('name', 'LIKE', "%{$val}%"),
        ])
        ->paginate($request);

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]->code)->toBe('MTR');
});

test('sorting by non-unique column maintains stable ordering via secondary id sort', function () {
    // Insert 4 items with same name in arbitrary order
    $u1 = TestUnit::create(['code' => 'C1', 'name' => 'Same Name']);
    $u2 = TestUnit::create(['code' => 'C2', 'name' => 'Same Name']);
    $u3 = TestUnit::create(['code' => 'C3', 'name' => 'Same Name']);
    $u4 = TestUnit::create(['code' => 'C4', 'name' => 'Same Name']);

    $request = Request::create('/test', 'GET', [
        'sort' => 'name',
        'direction' => 'asc',
    ]);

    $paginator = ListQuery::for(TestUnit::query())
        ->sortable(['name'])
        ->paginate($request);

    // Primary sort name asc, secondary sort id desc guarantees deterministic reverse-id order
    expect($paginator->items()[0]->id)->toBe($u4->id)
        ->and($paginator->items()[1]->id)->toBe($u3->id)
        ->and($paginator->items()[2]->id)->toBe($u2->id)
        ->and($paginator->items()[3]->id)->toBe($u1->id);
});

test('Paginated::from produces exact structured payload for Inertia', function () {
    $unit = TestUnit::create(['code' => 'BOX', 'name' => 'Box Container']);

    $request = Request::create('/test', 'GET', [
        'search' => 'Box',
        'sort' => 'name',
        'direction' => 'asc',
        'filter' => ['code' => 'BOX'],
    ]);

    $paginator = ListQuery::for(TestUnit::query())
        ->searchable(['name'])
        ->sortable(['name'])
        ->filterable(['code' => 'code'])
        ->paginate($request);

    $transformed = Paginated::from($paginator, function (TestUnit $unit) {
        return [
            'id' => $unit->id,
            'unit_code' => $unit->code,
        ];
    }, $request);

    expect($transformed)->toHaveKeys(['data', 'meta', 'query'])
        ->and($transformed['data'])->toBe([
            ['id' => $unit->id, 'unit_code' => 'BOX'],
        ])
        ->and($transformed['meta'])->toBe([
            'page' => 1,
            'per_page' => 25,
            'total' => 1,
            'last_page' => 1,
            'from' => 1,
            'to' => 1,
        ])
        ->and($transformed['query'])->toBe([
            'search' => 'Box',
            'sort' => 'name',
            'direction' => 'asc',
            'filter' => ['code' => 'BOX'],
        ]);
});

test('columns named date, sort, and count in filterable function as column names not PHP functions', function () {
    // TestUnit table has 'code' and 'name'. Let us alias filter keys 'date', 'sort', 'count' to 'code'
    TestUnit::create(['code' => 'D1', 'name' => 'Date Item']);
    TestUnit::create(['code' => 'S1', 'name' => 'Sort Item']);
    TestUnit::create(['code' => 'C1', 'name' => 'Count Item']);

    // 1. Filter key 'date' mapped to column 'code'
    $reqDate = Request::create('/test', 'GET', ['filter' => ['date' => 'D1']]);
    $resDate = ListQuery::for(TestUnit::query())
        ->filterable(['date' => 'code'])
        ->paginate($reqDate);

    expect($resDate->total())->toBe(1)
        ->and($resDate->items()[0]->name)->toBe('Date Item');

    // 2. Filter key 'sort' mapped to column 'code'
    $reqSort = Request::create('/test', 'GET', ['filter' => ['sort' => 'S1']]);
    $resSort = ListQuery::for(TestUnit::query())
        ->filterable(['sort' => 'code'])
        ->paginate($reqSort);

    expect($resSort->total())->toBe(1)
        ->and($resSort->items()[0]->name)->toBe('Sort Item');

    // 3. Filter key 'count' mapped to column 'code'
    $reqCount = Request::create('/test', 'GET', ['filter' => ['count' => 'C1']]);
    $resCount = ListQuery::for(TestUnit::query())
        ->filterable(['count' => 'code'])
        ->paginate($reqCount);

    expect($resCount->total())->toBe(1)
        ->and($resCount->items()[0]->name)->toBe('Count Item');
});

test('non-scalar filter values such as array filter[status][]=x are ignored without causing 500 error', function () {
    TestUnit::create(['code' => 'ACT', 'name' => 'Active Unit']);

    // Plain request with array filter value: filter[status][]=x
    $request = Request::create('/test', 'GET', [
        'filter' => [
            'status' => ['x', 'y'],
            'code' => 'ACT',
        ],
    ]);

    $paginator = ListQuery::for(TestUnit::query())
        ->filterable([
            'status' => 'code',
            'code' => 'code',
        ])
        ->paginate($request);

    // Array filter for status was safely ignored, scalar filter for code was applied
    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]->code)->toBe('ACT');
});
