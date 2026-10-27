<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Exceptions\InvalidStateTransitionException;
use UnitEnum;

final class DocumentStateMachine
{
    private function __construct() {}

    /**
     * Check if a transition between two status enum cases is permissible.
     */
    public static function canTransition(object $from, object $to): bool
    {
        if (! ($from instanceof UnitEnum) || ! ($to instanceof UnitEnum)) {
            return false;
        }

        if ($from::class !== $to::class) {
            return false;
        }

        if (method_exists($from, 'canTransitionTo')) {
            return (bool) $from->canTransitionTo($to);
        }

        return false;
    }

    /**
     * Assert that a transition is permissible, throwing InvalidStateTransitionException on violation.
     *
     * @throws InvalidStateTransitionException
     */
    public static function assertTransition(object $from, object $to): void
    {
        if (! self::canTransition($from, $to)) {
            $fromVal = property_exists($from, 'value') ? (string) $from->value : $from::class;
            $toVal = property_exists($to, 'value') ? (string) $to->value : $to::class;

            throw new InvalidStateTransitionException($fromVal, $toVal);
        }
    }
}
