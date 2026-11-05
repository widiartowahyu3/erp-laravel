<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;

test('all inventory routes are protected by authentication, explicit can middleware, and rate limited on write', function (): void {
    $routes = Route::getRoutes();
    $inventoryRoutes = [];

    foreach ($routes as $route) {
        $uri = $route->uri();
        if (str_starts_with($uri, 'inventory/')) {
            $inventoryRoutes[] = $route;
        }
    }

    expect($inventoryRoutes)->toHaveCount(12);

    foreach ($inventoryRoutes as $route) {
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

test('unauthenticated guests are redirected to login for main inventory routes', function (): void {
    $guestTestRoutes = [
        ['GET', '/inventory/stock-balances'],
        ['GET', '/inventory/stock-movements'],
        ['GET', '/inventory/stock-card'],
        ['GET', '/inventory/stock-adjustments'],
        ['GET', '/inventory/stock-adjustments/create'],
        ['POST', '/inventory/stock-adjustments'],
        ['GET', '/inventory/stock-adjustments/1'],
        ['GET', '/inventory/stock-adjustments/1/edit'],
        ['PUT', '/inventory/stock-adjustments/1'],
        ['POST', '/inventory/stock-adjustments/1/post'],
        ['POST', '/inventory/stock-adjustments/1/void'],
    ];

    foreach ($guestTestRoutes as [$method, $uri]) {
        $response = $this->call($method, $uri);
        $response->assertStatus(302);
        $response->assertRedirect('/login');
    }
});
