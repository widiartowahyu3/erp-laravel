<?php

declare(strict_types=1);

namespace App\Exceptions\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

trait RendersAsFormError
{
    /**
     * Get the form field associated with the error.
     */
    public function getField(): string
    {
        return isset($this->field) && $this->field !== ''
            ? $this->field
            : 'form';
    }

    /**
     * Get the HTTP status code.
     */
    public function getStatusCode(): int
    {
        if (property_exists($this, 'statusCode') && is_int($this->statusCode) && $this->statusCode > 0) {
            return $this->statusCode;
        }

        $code = (int) $this->getCode();

        return ($code >= 400 && $code < 600) ? $code : 422;
    }

    /**
     * Get optional headers to include in the response.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        if (property_exists($this, 'headers') && is_array($this->headers)) {
            /** @var array<string, string> $headers */
            $headers = $this->headers;

            return $headers;
        }

        if (property_exists($this, 'seconds') && is_int($this->seconds)) {
            return ['Retry-After' => (string) $this->seconds];
        }

        return [];
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render(Request $request): Response
    {
        $message = $this->getMessage();
        $field = $this->getField();
        $status = $this->getStatusCode();
        $headers = $this->getHeaders();

        // 1. Inertia requests: redirect back (303 See Other) with inline error in session
        if ($request->header('X-Inertia') || $request->header('x-inertia')) {
            return back(303)->withErrors([
                $field => $message,
            ]);
        }

        // 2. JSON requests: structured JSON error
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => [
                    $field => [$message],
                ],
            ], $status, $headers);
        }

        // 3. Non-Inertia HTML requests: generic error page without leaking stack trace
        return response()->view('errors.business', [
            'message' => $message,
            'status' => $status,
        ], $status, $headers);
    }
}
