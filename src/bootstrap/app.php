<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

        $middleware->trustHosts(at: function () {
            $hosts = [parse_url((string) config('app.url'), PHP_URL_HOST)];

            if (! app()->isProduction()) {
                $hosts[] = 'localhost';
                $hosts[] = '127.0.0.1';
            }

            return array_values(array_filter($hosts));
        });

        $middleware->redirectTo(
            guests: '/login',
            users: '/'
        );

        $middleware->append(SecurityHeaders::class);

        $middleware->appendToGroup('web', [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($e instanceof ValidationException && $e->status === 429) {
                return $response->setStatusCode(429);
            }

            $status = $response->getStatusCode();

            // Intercept 403 Forbidden to record audit log and render Errors/Error
            if ($status === 403) {
                $user = $request->user();
                $userId = $user !== null ? (int) $user->id : 0;
                $permission = '';
                if ($e instanceof AuthorizationException && method_exists($e, 'ability') && $e->ability()) {
                    $permission = (string) $e->ability();
                }

                if ($permission === '') {
                    $route = $request->route();
                    if ($route !== null) {
                        foreach ($route->gatherMiddleware() as $m) {
                            if (is_string($m) && str_starts_with($m, 'can:')) {
                                $parts = explode(',', substr($m, 4));
                                $permission = trim($parts[0]);
                                break;
                            }
                        }
                    }
                }

                if ($permission === '') {
                    $permission = $e->getMessage();
                }

                AuditLogger::record(
                    event: 'access.denied',
                    auditableType: 'auth',
                    auditableId: $userId,
                    new: ['permission' => $permission !== '' ? $permission : 'unauthorized'],
                    userId: $userId > 0 ? $userId : null
                );

                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'message' => 'Akses ditolak.',
                    ], 403);
                }

                if ($request->header('X-Inertia')) {
                    return response()->json([
                        'component' => 'Errors/Error',
                        'props' => [
                            'status' => 403,
                            'message' => 'Anda tidak memiliki hak akses untuk membuka halaman ini.',
                        ],
                        'url' => $request->getRequestUri(),
                        'version' => '',
                    ], 403, ['X-Inertia' => 'true']);
                }

                return response()->view('errors.minimal', [
                    'status' => 403,
                    'message' => 'Akses ditolak.',
                ], 403);
            }

            // Always ensure 404 does not leak stack traces or internal paths
            if ($status === 404) {
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'message' => 'Halaman tidak ditemukan.',
                    ], 404);
                }

                if ($request->header('X-Inertia')) {
                    return response()->json([
                        'component' => 'Errors/Error',
                        'props' => [
                            'status' => 404,
                            'message' => 'Halaman tidak ditemukan.',
                        ],
                        'url' => $request->getRequestUri(),
                        'version' => '',
                    ], 404, ['X-Inertia' => 'true']);
                }

                return response()->view('errors.minimal', [
                    'status' => 404,
                    'message' => 'Halaman tidak ditemukan.',
                ], 404);
            }

            if (! app()->isProduction()) {
                return $response;
            }

            $handledStatuses = [403, 419, 429, 500, 503];

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
