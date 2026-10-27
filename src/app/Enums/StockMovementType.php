<?php

declare(strict_types=1);

namespace App\Enums;

enum StockMovementType: string
{
    case RECEIPT = 'receipt';
    case DELIVERY = 'delivery';
    case ADJUSTMENT_IN = 'adjustment_in';
    case ADJUSTMENT_OUT = 'adjustment_out';

    public function label(): string
    {
        return match ($this) {
            self::RECEIPT => 'Penerimaan Barang',
            self::DELIVERY => 'Pengiriman Barang',
            self::ADJUSTMENT_IN => 'Penyesuaian Masuk',
            self::ADJUSTMENT_OUT => 'Penyesuaian Keluar',
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return false;
    }
}
