<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;

test('all sales routes are protected by authentication, explicit can middleware, and rate limited on write', function (): void {
    $routes = Route::getRoutes();
    $salesRoutes = [];

    foreach ($routes as $route) {
        $uri = $route->uri();
        if (str_starts_with($uri, 'sales/')) {
            $salesRoutes[] = $route;
        }
    }

    expect($salesRoutes)->toHaveCount(25);

    foreach ($salesRoutes as $route) {
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

test('unauthenticated guests are redirected to login for main sales routes', function (): void {
    $guestTestRoutes = [
        ['GET', '/sales/sales-orders'],
        ['GET', '/sales/sales-orders/create'],
        ['POST', '/sales/sales-orders'],
        ['GET', '/sales/sales-orders/1'],
        ['GET', '/sales/sales-orders/1/edit'],
        ['PUT', '/sales/sales-orders/1'],
        ['POST', '/sales/sales-orders/1/approve'],
        ['POST', '/sales/sales-orders/1/void'],
        ['POST', '/sales/sales-orders/1/close'],
        ['GET', '/sales/delivery-orders'],
        ['GET', '/sales/delivery-orders/create'],
        ['POST', '/sales/delivery-orders'],
        ['GET', '/sales/delivery-orders/1'],
        ['GET', '/sales/delivery-orders/1/edit'],
        ['PUT', '/sales/delivery-orders/1'],
        ['POST', '/sales/delivery-orders/1/post'],
        ['POST', '/sales/delivery-orders/1/void'],
    ];

    foreach ($guestTestRoutes as [$method, $uri]) {
        $response = $this->call($method, $uri);
        expect($response->status())->toBe(302)
            ->and($response->headers->get('Location'))->toContain('/login');
    }
});
