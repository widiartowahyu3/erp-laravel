<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Concerns\RequiresTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use LogicException;

class RequiresTransactionConsumer
{
    use RequiresTransaction;

    public static function doSomething(): void
    {
        self::ensureInTransaction();
    }
}

test('ensureInTransaction throws LogicException when outside database transaction', function (): void {
    // If we're not using DatabaseTransactions in this test, transactionLevel should be 0
    expect(DB::transactionLevel())->toBe(0);

    expect(fn () => RequiresTransactionConsumer::doSomething())
        ->toThrow(LogicException::class, 'This operation must be executed within an active database transaction.');
});

test('ensureInTransaction succeeds when inside database transaction', function (): void {
    DB::transaction(function (): void {
        expect(DB::transactionLevel())->toBeGreaterThan(0);
        RequiresTransactionConsumer::doSomething();
    });

    expect(true)->toBeTrue();
});
