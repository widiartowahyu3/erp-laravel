<?php

declare(strict_types=1);

namespace App\Enums;

enum JournalStatus: string
{
    case DRAFT = 'draft';
    case POSTED = 'posted';
    case VOID = 'void';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::POSTED => 'Dibukukan',
            self::VOID => 'Dibatalkan',
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::DRAFT => in_array($to, [self::POSTED, self::VOID], true),
            self::POSTED => $to === self::VOID,
            self::VOID => false,
        };
    }
}
