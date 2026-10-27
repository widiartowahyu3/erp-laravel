<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseInvoiceStatus: string
{
    case DRAFT = 'draft';
    case POSTED = 'posted';
    case PARTIALLY_PAID = 'partially_paid';
    case PAID = 'paid';
    case VOID = 'void';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::POSTED => 'Dibukukan',
            self::PARTIALLY_PAID => 'Dibayar Sebagian',
            self::PAID => 'Lunas',
            self::VOID => 'Dibatalkan',
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::DRAFT => in_array($to, [self::POSTED, self::VOID], true),
            self::POSTED => in_array($to, [self::PARTIALLY_PAID, self::PAID, self::VOID], true),
            self::PARTIALLY_PAID => in_array($to, [self::PAID, self::POSTED], true),
            self::PAID => in_array($to, [self::PARTIALLY_PAID, self::POSTED], true),
            self::VOID => false,
        };
    }
}
