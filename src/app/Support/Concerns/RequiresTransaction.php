<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Support\Facades\DB;
use LogicException;

trait RequiresTransaction
{
    /**
     * Ensure the current operation is executed within an active database transaction.
     *
     * @throws LogicException
     */
    protected static function ensureInTransaction(?string $connection = null): void
    {
        if (DB::connection($connection)->transactionLevel() <= 0) {
            throw new LogicException('This operation must be executed within an active database transaction.');
        }
    }
}
