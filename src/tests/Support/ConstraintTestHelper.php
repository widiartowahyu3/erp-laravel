<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\QueryException;
use PHPUnit\Framework\Assert;
use Throwable;

final class ConstraintTestHelper
{
    /**
     * Assert that a callback fails due to a specific CHECK constraint.
     */
    public static function assertConstraintFailure(callable $callback, string $expectedConstraintName, string $message = ''): void
    {
        try {
            $callback();
            Assert::fail($message !== '' ? $message : "Expected CHECK constraint [{$expectedConstraintName}] failure, but no exception was thrown.");
        } catch (QueryException $e) {
            $errorMsg = $e->getMessage();
            Assert::assertTrue(
                str_contains($errorMsg, $expectedConstraintName),
                "Expected error message to contain CHECK constraint [{$expectedConstraintName}], but got: {$errorMsg}"
            );
        } catch (Throwable $e) {
            Assert::fail("Expected QueryException with constraint [{$expectedConstraintName}], but caught ".get_class($e).": {$e->getMessage()}");
        }
    }

    /**
     * Assert that a callback fails due to a unique key constraint violation.
     */
    public static function assertUniqueFailure(callable $callback, string $expectedKeyName, string $message = ''): void
    {
        try {
            $callback();
            Assert::fail($message !== '' ? $message : "Expected UNIQUE key [{$expectedKeyName}] failure, but no exception was thrown.");
        } catch (QueryException $e) {
            $errorMsg = $e->getMessage();
            $containsUnique = str_contains($errorMsg, 'Duplicate entry') || str_contains($errorMsg, '1062');
            $containsKey = str_contains($errorMsg, $expectedKeyName);

            Assert::assertTrue(
                $containsUnique && $containsKey,
                "Expected error message to indicate duplicate entry for key [{$expectedKeyName}], but got: {$errorMsg}"
            );
        } catch (Throwable $e) {
            Assert::fail("Expected QueryException with unique key [{$expectedKeyName}], but caught ".get_class($e).": {$e->getMessage()}");
        }
    }

    /**
     * Assert that a callback fails due to a foreign key constraint violation.
     */
    public static function assertForeignKeyFailure(callable $callback, ?string $expectedConstraintName = null, string $message = ''): void
    {
        try {
            $callback();
            Assert::fail($message !== '' ? $message : 'Expected foreign key constraint failure, but no exception was thrown.');
        } catch (QueryException $e) {
            $errorMsg = $e->getMessage();
            $isFkError = str_contains($errorMsg, 'foreign key constraint fails') || str_contains($errorMsg, '1451') || str_contains($errorMsg, '1452');

            Assert::assertTrue(
                $isFkError,
                "Expected error message to indicate foreign key violation, but got: {$errorMsg}"
            );

            if ($expectedConstraintName !== null) {
                Assert::assertTrue(
                    str_contains($errorMsg, $expectedConstraintName),
                    "Expected foreign key error to reference constraint [{$expectedConstraintName}], but got: {$errorMsg}"
                );
            }
        } catch (Throwable $e) {
            Assert::fail('Expected QueryException for foreign key failure, but caught '.get_class($e).": {$e->getMessage()}");
        }
    }

    /**
     * Assert that a callback fails due to a custom trigger SIGNAL (45000).
     */
    public static function assertTriggerFailure(callable $callback, string $expectedMessage, string $message = ''): void
    {
        try {
            $callback();
            Assert::fail($message !== '' ? $message : "Expected trigger failure with message [{$expectedMessage}], but no exception was thrown.");
        } catch (QueryException $e) {
            $errorMsg = $e->getMessage();
            Assert::assertTrue(
                str_contains($errorMsg, $expectedMessage),
                "Expected error message to contain trigger text [{$expectedMessage}], but got: {$errorMsg}"
            );
        } catch (Throwable $e) {
            Assert::fail("Expected QueryException with trigger message [{$expectedMessage}], but caught ".get_class($e).": {$e->getMessage()}");
        }
    }
}
