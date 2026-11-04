<?php

declare(strict_types=1);

use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\TooManyAttemptsException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
        $exceptions->dontReport([
            TooManyAttemptsException::class,
            ThrottleRequestsException::class,
            BusinessRuleException::class,
            InvalidStateTransitionException::class,
            InsufficientStockException::class,
            DocumentNotEditableException::class,
        ]);

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            $headers = $e->getHeaders();
            $seconds = (int) ($headers['Retry-After'] ?? 60);
            $message = "Terlalu banyak permintaan. Coba lagi dalam {$seconds} detik.";

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $message,
                ], 429, $headers);
            }

            if ($request->header('X-Inertia')) {
                return redirect()->back()
                    ->withInput($request->except(['password', 'current_password', 'password_confirmation', '_token']))
                    ->with('error', $message)
                    ->setStatusCode(303)
                    ->withHeaders($headers);
            }

            return response()->view('errors.minimal', [
                'status' => 429,
                'message' => $message,
            ], 429, $headers);
        });

        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            $msg = $e->getMessage();
            $constraint = '';
            if (preg_match("/for key '([^']+)'/", $msg, $matches)) {
                $constraint = (string) preg_replace('/^.*\./', '', $matches[1]);
            }

            // Strictly check for known master data and user unique constraints
            if ($constraint !== '' && preg_match('/^(units|product_categories|products|warehouses|suppliers|customers|tax_rates|payment_terms|users|chart_of_accounts)_(code|sku|barcode|email)_unique$/', $constraint, $m)) {
                $column = $m[2];
                $field = $column;
                $validationMsg = match ($column) {
                    'sku' => 'Kode SKU sudah dipakai, termasuk oleh data yang sudah dihapus.',
                    'barcode' => 'Kode barcode sudah dipakai, termasuk oleh data yang sudah dihapus.',
                    'email' => 'Email sudah dipakai, termasuk oleh data yang sudah dihapus.',
                    default => 'Kode sudah dipakai, termasuk oleh data yang sudah dihapus.',
                };

                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'message' => $validationMsg,
                        'errors' => [
                            $field => [$validationMsg],
                        ],
                    ], 422);
                }

                return redirect()->back()
                    ->withInput($request->except(['password', 'current_password', 'password_confirmation', '_token']))
                    ->withErrors([$field => $validationMsg]);
            }

            // Unknown unique violation indicates a bug or document concurrency collision
            report($e);

            $genericConflictMsg = 'Terjadi konflik data. Silakan muat ulang dan coba lagi.';

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $genericConflictMsg,
                ], 409);
            }

            return redirect()->back()
                ->withInput($request->except(['password', 'current_password', 'password_confirmation', '_token']))
                ->with('error', $genericConflictMsg)
                ->setStatusCode(303);
        });

        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($e instanceof TooManyAttemptsException || $e instanceof ThrottleRequestsException || $e instanceof BusinessRuleException || $e instanceof UniqueConstraintViolationException) {
                return $response;
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

                $effectivePerm = $permission !== '' ? $permission : 'unauthorized';
                $ip = (string) ($request->ip() ?? '');
                $dedupKey = "audit_dedup|access.denied|{$userId}|{$effectivePerm}|{$ip}";

                if (Cache::add($dedupKey, true, 60)) {
                    AuditLogger::record(
                        event: 'access.denied',
                        auditableType: 'auth',
                        auditableId: $userId,
                        new: ['permission' => $effectivePerm],
                        userId: $userId > 0 ? $userId : null
                    );
                }

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
