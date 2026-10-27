<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentMethod: string
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

    public function canTransitionTo(self $to): bool
    {
        return false;
    }
}
