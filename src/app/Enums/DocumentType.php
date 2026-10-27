<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentType: string
{
    case PO = 'PO';
    case GR = 'GR';
    case SO = 'SO';
    case DO = 'DO';
    case SA = 'SA';
    case SI = 'SI';
    case PI = 'PI';
    case PAY = 'PAY';
    case JV = 'JV';

    /**
     * Get the default prefix for this document type.
     */
    public function prefix(): string
    {
        return $this->value;
    }

    /**
     * Get the Indonesian human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::PO => 'Pesanan Pembelian',
            self::SO => 'Pesanan Penjualan',
            self::GR => 'Penerimaan Barang',
            self::DO => 'Surat Jalan',
            self::SA => 'Penyesuaian Stok',
            self::SI => 'Faktur Penjualan',
            self::PI => 'Faktur Pembelian',
            self::PAY => 'Pembayaran',
            self::JV => 'Jurnal Umum',
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return false;
    }
}
