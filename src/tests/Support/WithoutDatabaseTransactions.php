<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Trait used to mark test classes that manage their own database transactions
 * and should opt out of the global DatabaseTransactions handling.
 */
trait WithoutDatabaseTransactions {}
