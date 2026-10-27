<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Contracts\HasStateTransitions;

enum PurchaseOrderStatus: string implements HasStateTransitions
{
    case DRAFT = 'draft';
    case APPROVED = 'approved';
    case PARTIALLY_RECEIVED = 'partially_received';
    case RECEIVED = 'received';
    case CLOSED = 'closed';
    case VOID = 'void';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::APPROVED => 'Disetujui',
            self::PARTIALLY_RECEIVED => 'Diterima Sebagian',
            self::RECEIVED => 'Diterima',
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
            self::APPROVED => in_array($to, [self::PARTIALLY_RECEIVED, self::RECEIVED, self::VOID], true),
            self::PARTIALLY_RECEIVED => in_array($to, [self::RECEIVED, self::CLOSED], true),
            self::RECEIVED => $to === self::CLOSED,
            self::CLOSED, self::VOID => false,
        };
    }
}
