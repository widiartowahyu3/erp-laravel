<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);

        $middleware->trustHosts(at: fn () => array_values(array_filter([
            'localhost',
            '127.0.0.1',
            parse_url((string) config('app.url'), PHP_URL_HOST),
        ])));

        $middleware->appendToGroup('web', [
            SecurityHeaders::class,
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if (! app()->isProduction()) {
                return $response;
            }

            $status = $response->getStatusCode();
            $handledStatuses = [403, 404, 419, 429, 500, 503];

            if (in_array($status, $handledStatuses, true)) {
                $messages = [
                    403 => 'Akses ditolak.',
                    404 => 'Halaman tidak ditemukan.',
                    419 => 'Sesi telah kedaluwarsa. Silakan muat ulang.',
                    429 => 'Terlalu banyak permintaan. Silakan coba lagi nanti.',
                    500 => 'Terjadi kesalahan pada server.',
                    503 => 'Layanan sedang dalam pemeliharaan.',
                ];
                $message = $messages[$status] ?? 'Terjadi kesalahan.';

                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'message' => $message,
                    ], $status);
                }

                if ($request->header('X-Inertia')) {
                    return response()->json([
                        'component' => 'Errors/Error',
                        'props' => [
                            'status' => $status,
                            'message' => $message,
                        ],
                        'url' => $request->getRequestUri(),
                        'version' => '',
                    ], $status, ['X-Inertia' => 'true']);
                }

                return response()->view('errors.minimal', [
                    'status' => $status,
                    'message' => $message,
                ], $status);
            }

            return $response;
        });
    })->create();
