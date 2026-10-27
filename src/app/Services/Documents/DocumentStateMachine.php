<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\Contracts\HasStateTransitions;
use App\Exceptions\InvalidStateTransitionException;

final class DocumentStateMachine
{
    private function __construct() {}

    /**
     * Check if a transition between two status enum cases is permissible.
     */
    public static function canTransition(HasStateTransitions $from, HasStateTransitions $to): bool
    {
        if ($from::class !== $to::class) {
            return false;
        }

        return $from->canTransitionTo($to);
    }

    /**
     * Assert that a transition is permissible, throwing InvalidStateTransitionException on violation.
     *
     * @throws InvalidStateTransitionException
     */
    public static function assertTransition(HasStateTransitions $from, HasStateTransitions $to): void
    {
        if (! self::canTransition($from, $to)) {
            $fromVal = (string) $from->value;
            $toVal = (string) $to->value;

            throw new InvalidStateTransitionException($fromVal, $toVal);
        }
    }
}
