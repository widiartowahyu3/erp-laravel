<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\WithoutDatabaseTransactions;

abstract class TestCase extends BaseTestCase
{
    /**
     * The database connections that should have transactions.
     *
     * @var array<int, string|null>|null
     */
    public ?array $connectionsToTransact = null;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        $uses = class_uses_recursive(static::class);

        if (in_array(WithoutDatabaseTransactions::class, $uses, true)
            || str_contains(static::class, 'DocumentNumberServiceTest')
        ) {
            $this->connectionsToTransact = [];
        } else {
            $this->connectionsToTransact = [null];
        }

        parent::setUp();
    }
}
