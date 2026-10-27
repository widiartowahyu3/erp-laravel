<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Concerns\RendersAsFormError;
use Exception;
use Throwable;

class BusinessRuleException extends Exception
{
    use RendersAsFormError;

    public function __construct(
        string $message,
        int $code = 422,
        public readonly ?string $field = 'form',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
