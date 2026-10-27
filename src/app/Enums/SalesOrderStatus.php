<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Contracts\HasStateTransitions;

enum SalesOrderStatus: string implements HasStateTransitions
{
    case DRAFT = 'draft';
    case APPROVED = 'approved';
    case PARTIALLY_DELIVERED = 'partially_delivered';
    case DELIVERED = 'delivered';
    case CLOSED = 'closed';
    case VOID = 'void';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::APPROVED => 'Disetujui',
            self::PARTIALLY_DELIVERED => 'Terkirim Sebagian',
            self::DELIVERED => 'Terkirim',
            self::CLOSED => 'Ditutup',
            self::VOID => 'Dibatalkan',
        };
    }

    public function canTransitionTo(HasStateTransitions $to): bool
    {
        if (! ($to instanceof self)) {
            return false;
        }

        return match ($this) {
            self::DRAFT => in_array($to, [self::APPROVED, self::VOID], true),
            self::APPROVED => in_array($to, [self::PARTIALLY_DELIVERED, self::DELIVERED, self::VOID], true),
            self::PARTIALLY_DELIVERED => in_array($to, [self::DELIVERED, self::CLOSED], true),
            self::DELIVERED => $to === self::CLOSED,
            self::CLOSED, self::VOID => false,
        };
    }
}
