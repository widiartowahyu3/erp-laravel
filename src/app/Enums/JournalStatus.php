<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Contracts\HasStateTransitions;

enum JournalStatus: string implements HasStateTransitions
{
    case DRAFT = 'draft';
    case POSTED = 'posted';
    case VOID = 'void';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::POSTED => 'Diposting',
            self::VOID => 'Dibatalkan',
        };
    }

    public function canTransitionTo(HasStateTransitions $to): bool
    {
        if (! ($to instanceof self)) {
            return false;
        }

        return match ($this) {
            self::DRAFT => in_array($to, [self::POSTED, self::VOID], true),
            self::POSTED => $to === self::VOID,
            self::VOID => false,
        };
    }
}
