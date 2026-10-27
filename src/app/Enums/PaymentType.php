<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentType: string
{
    case RECEIPT = 'receipt';
    case DISBURSEMENT = 'disbursement';

    public function label(): string
    {
        return match ($this) {
            self::RECEIPT => 'Penerimaan',
            self::DISBURSEMENT => 'Pengeluaran',
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return false;
    }
}
