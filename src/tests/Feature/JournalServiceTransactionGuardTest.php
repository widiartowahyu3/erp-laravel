<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Accounting\JournalService;
use App\Support\Accounting\JournalDraft;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\WithoutDatabaseTransactions;

uses(WithoutDatabaseTransactions::class);

test('automatic journal methods throw LogicException outside an active database transaction', function (): void {
    expect(DB::transactionLevel())->toBe(0);

    $service = new JournalService;
    $draft = (new JournalDraft('Otomatis'))->debit('inventory', '10.00')->credit('grir', '10.00');
    $date = CarbonImmutable::parse('2026-11-04');

    expect(fn () => $service->postAutomatic($draft, 'goods_receipt', 1, $date))
        ->toThrow(LogicException::class, 'This operation must be executed within an active database');

    expect(fn () => $service->reverseAutomatic('goods_receipt', 1, $date))
        ->toThrow(LogicException::class, 'This operation must be executed within an active database');
});
