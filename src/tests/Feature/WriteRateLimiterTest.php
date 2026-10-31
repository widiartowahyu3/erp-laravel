<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::post('/_test/erp-write-probe', function () {
        return response()->json(['status' => 'ok']);
    })->middleware(['web', 'auth', 'throttle:erp-write']);

    Route::get('/_test/erp-read-probe', function () {
        return response()->json(['status' => 'ok']);
    })->middleware(['web', 'auth', 'throttle:erp-write']);
});

test('all authenticated write routes have throttle:erp-write middleware in stack', function (): void {
    $routes = Route::getRoutes();
    $writeRoutes = [];
    $unthrottled = [];

    foreach ($routes as $route) {
        $methods = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']);
        if (empty($methods)) {
            continue;
        }

        $uri = $route->uri();
        if ($uri === 'login' || str_starts_with($uri, '_test/')) {
            continue;
        }

        $middleware = $route->gatherMiddleware();
        if (in_array('auth', $middleware, true)) {
            $writeRoutes[] = $uri;
            if (! in_array('throttle:erp-write', $middleware, true)) {
                $unthrottled[] = implode('|', $methods).' '.$uri;
            }
        }
    }

    expect($writeRoutes)->not->toBeEmpty()
        ->and($unthrottled)->toBeEmpty('All authenticated write routes must have throttle:erp-write');

    // Confirm specifically for master, purchasing, inventory, account, and logout
    $checkedUris = [
        'master/units',
        'master/product-categories',
        'master/products',
        'master/warehouses',
        'master/suppliers',
        'master/customers',
        'master/tax-rates',
        'master/payment-terms',
        'inventory/stock-adjustments',
        'purchasing/purchase-orders',
        'purchasing/goods-receipts',
        'account/password',
        'logout',
    ];

    foreach ($checkedUris as $checkedUri) {
        expect(in_array($checkedUri, $writeRoutes, true))->toBeTrue("Write route {$checkedUri} must be registered and throttled");
    }
});

test('erp-write rate limiter throttles 61st write request with custom indonesian 429 message', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    RateLimiter::clear((string) $userA->getAuthIdentifier());
    RateLimiter::clear((string) $userB->getAuthIdentifier());

    $this->actingAs($userA);

    // Send 60 write requests - all must succeed (200)
    for ($i = 1; $i <= 60; $i++) {
        $response = $this->postJson('/_test/erp-write-probe');
        expect($response->status())->toBe(200, "Request #{$i} should have succeeded with 200");
    }

    // 61st JSON request must be throttled with 429 and Indonesian message
    $response61 = $this->postJson('/_test/erp-write-probe');
    expect($response61->status())->toBe(429)
        ->and($response61->headers->has('Retry-After'))->toBeTrue()
        ->and($response61->json('message'))->toMatch('/^Terlalu banyak permintaan\. Coba lagi dalam \d+ detik\.$/')
        ->and($response61->getContent())->not->toContain('Stack trace:')
        ->and($response61->getContent())->not->toContain('vendor/');

    // User B is isolated and unaffected (positive control)
    $this->actingAs($userB);
    $responseUserB = $this->postJson('/_test/erp-write-probe');
    expect($responseUserB->status())->toBe(200);
});

test('erp-write rate limiter renders 303 redirect with flash error for inertia requests', function (): void {
    $user = User::factory()->create();
    RateLimiter::clear((string) $user->getAuthIdentifier());

    $this->actingAs($user);

    for ($i = 1; $i <= 60; $i++) {
        $res = $this->post('/_test/erp-write-probe', [], ['X-Inertia' => 'true']);
        expect($res->status())->toBe(200);
    }

    $res61 = $this->from('/dashboard')->post('/_test/erp-write-probe', [], ['X-Inertia' => 'true']);
    expect($res61->status())->toBe(303)
        ->and($res61->headers->has('Retry-After'))->toBeTrue()
        ->and(session('error'))->toMatch('/^Terlalu banyak permintaan\. Coba lagi dalam \d+ detik\.$/');
});

test('erp-write rate limiter never throttles GET requests even after 100 requests', function (): void {
    $user = User::factory()->create();
    RateLimiter::clear((string) $user->getAuthIdentifier());

    $this->actingAs($user);

    for ($i = 1; $i <= 100; $i++) {
        $response = $this->getJson('/_test/erp-read-probe');
        expect($response->status())->toBe(200, "GET request #{$i} should not be throttled");
    }
});
