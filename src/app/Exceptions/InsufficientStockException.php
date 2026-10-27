<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

final class InsufficientStockException extends BusinessRuleException
{
    public function __construct(
        string $message = 'Stok produk tidak mencukupi untuk memenuhi transaksi.',
        int $code = 422,
        string $field = 'quantity',
        ?Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            code: $code,
            field: $field,
            previous: $previous
        );
    }
}
