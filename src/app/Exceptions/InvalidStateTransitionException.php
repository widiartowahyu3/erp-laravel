<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

final class InvalidStateTransitionException extends BusinessRuleException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        string $message = '',
        int $code = 409,
        public readonly ?string $documentType = null,
        ?Throwable $previous = null
    ) {
        $finalMessage = $message !== ''
            ? $message
            : "Transisi status dari '{$from}' ke '{$to}' tidak diizinkan.";

        parent::__construct(
            message: $finalMessage,
            code: $code,
            field: 'status',
            previous: $previous
        );
    }
}
