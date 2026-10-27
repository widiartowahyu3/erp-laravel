<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Contracts\HasStateTransitions;

enum AccountType: string implements HasStateTransitions
{
    case ASSET = 'asset';
    case LIABILITY = 'liability';
    case EQUITY = 'equity';
    case REVENUE = 'revenue';
    case EXPENSE = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::ASSET => 'Aset',
            self::LIABILITY => 'Kewajiban',
            self::EQUITY => 'Ekuitas',
            self::REVENUE => 'Pendapatan',
            self::EXPENSE => 'Beban',
        };
    }

    public function canTransitionTo(HasStateTransitions $to): bool
    {
        return false;
    }
}
