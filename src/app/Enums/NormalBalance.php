<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Contracts\HasStateTransitions;

enum NormalBalance: string implements HasStateTransitions
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::DEBIT => 'Debit',
            self::CREDIT => 'Kredit',
        };
    }

    public function canTransitionTo(HasStateTransitions $to): bool
    {
        return false;
    }
}
