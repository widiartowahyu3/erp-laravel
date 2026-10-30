<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;

test('all purchasing routes are protected by authentication, explicit can middleware, and rate limited on write', function (): void {
    $routes = Route::getRoutes();
    $purchasingRoutes = [];

    foreach ($routes as $route) {
        $uri = $route->uri();
        if (str_starts_with($uri, 'purchasing/')) {
            $purchasingRoutes[] = $route;
        }
    }

    expect($purchasingRoutes)->toHaveCount(17);

    foreach ($purchasingRoutes as $route) {
        $uri = $route->uri();
        $methods = implode('|', $route->methods());
        $middleware = $route->gatherMiddleware();

        // 1. Must be protected by auth middleware
        $hasAuth = in_array('auth', $middleware, true);
        expect($hasAuth)->toBeTrue("Route [{$methods}] {$uri} must have 'auth' middleware.");

        // 2. Must have explicit can: permission check
        $canMiddleware = array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'can:'));
        expect($canMiddleware)->not->toBeEmpty("Route [{$methods}] {$uri} must have explicit 'can:' authorization middleware.");

        // 3. All non-GET routes must have throttle:erp-write middleware
        $isNonGet = ! in_array('GET', $route->methods(), true) && ! in_array('HEAD', $route->methods(), true);
        if ($isNonGet) {
            $hasThrottle = in_array('throttle:erp-write', $middleware, true);
            expect($hasThrottle)->toBeTrue("Non-GET route [{$methods}] {$uri} must have 'throttle:erp-write' middleware.");
        }
    }
});

test('unauthenticated guests are redirected to login for main purchasing routes', function (): void {
    $guestTestRoutes = [
        ['GET', '/purchasing/purchase-orders'],
        ['GET', '/purchasing/purchase-orders/create'],
        ['POST', '/purchasing/purchase-orders'],
        ['GET', '/purchasing/purchase-orders/1'],
        ['GET', '/purchasing/purchase-orders/1/edit'],
        ['PUT', '/purchasing/purchase-orders/1'],
        ['POST', '/purchasing/purchase-orders/1/approve'],
        ['POST', '/purchasing/purchase-orders/1/void'],
        ['POST', '/purchasing/purchase-orders/1/close'],
        ['GET', '/purchasing/goods-receipts'],
        ['GET', '/purchasing/goods-receipts/create'],
        ['POST', '/purchasing/goods-receipts'],
        ['GET', '/purchasing/goods-receipts/1'],
        ['GET', '/purchasing/goods-receipts/1/edit'],
        ['PUT', '/purchasing/goods-receipts/1'],
        ['POST', '/purchasing/goods-receipts/1/post'],
        ['POST', '/purchasing/goods-receipts/1/void'],
    ];

    foreach ($guestTestRoutes as [$method, $uri]) {
        $response = $this->call($method, $uri);
        $response->assertStatus(302);
        $response->assertRedirect('/login');
    }
});
