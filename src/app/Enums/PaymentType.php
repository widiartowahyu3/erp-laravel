<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Contracts\HasStateTransitions;

enum PaymentType: string implements HasStateTransitions
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

    public function canTransitionTo(HasStateTransitions $to): bool
    {
        return false;
    }
}
