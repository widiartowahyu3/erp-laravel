<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

final class DocumentNotEditableException extends BusinessRuleException
{
    public function __construct(
        string $message = 'Dokumen pada status saat ini tidak dapat diubah.',
        int $code = 409,
        string $field = 'status',
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
