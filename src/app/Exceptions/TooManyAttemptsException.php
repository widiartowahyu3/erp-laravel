<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TooManyAttemptsException extends Exception
{
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

    /**
     * Determine if the exception should be reported.
     */
    public function report(): bool
    {
        return false;
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render(Request $request): Response
    {
        $message = $this->getMessage();

        // 1. Inertia requests: redirect back (303 See Other) with inline error in session
        if ($request->header('X-Inertia') || $request->header('x-inertia')) {
            return back(303)->withErrors([
                $this->field => $message,
            ]);
        }

        // 2. Non-Inertia / API / JSON requests: HTTP 429 with Retry-After header and payload
        return response()->json([
            'message' => $message,
            'errors' => [
                $this->field => [$message],
            ],
        ], 429, [
            'Retry-After' => (string) $this->seconds,
        ]);
    }
}
