<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Concerns\RendersAsFormError;
use Exception;

final class TooManyAttemptsException extends Exception
{
    use RendersAsFormError;

    /**
     * Create a new exception instance.
     */
    public function __construct(
        public readonly string $key,
        public readonly int $seconds,
        public readonly string $field = 'email',
        ?string $message = null,
    ) {
        $msg = $message ?? "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.";
        parent::__construct($msg, 429);
    }
}
