<?php

declare(strict_types=1);

namespace App\Enums\Contracts;

use BackedEnum;

interface HasStateTransitions extends BackedEnum
{
    /**
     * Check if transition to target state is allowed.
     */
    public function canTransitionTo(self $to): bool;
}
