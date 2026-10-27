<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Contracts\HasStateTransitions;

enum PaymentMethod: string implements HasStateTransitions
{
    case CASH = 'cash';
    case BANK_TRANSFER = 'bank_transfer';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Tunai',
            self::BANK_TRANSFER => 'Transfer Bank',
            self::OTHER => 'Lainnya',
        };
    }

    public function canTransitionTo(HasStateTransitions $to): bool
    {
        return false;
    }
}
