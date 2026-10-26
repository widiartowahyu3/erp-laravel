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
        return match ($this) {
            self::PO => 'PO',
            self::GR => 'GR',
            self::SO => 'SO',
            self::DO => 'DO',
            self::SA => 'SA',
            self::SI => 'SI',
            self::PI => 'PI',
            self::PAY => 'PAY',
            self::JV => 'JV',
        };
    }
}
