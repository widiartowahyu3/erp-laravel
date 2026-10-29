<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;

test('all master data routes are protected by authentication and explicit can middleware', function (): void {
    $routes = Route::getRoutes();
    $masterRoutes = [];

    foreach ($routes as $route) {
        $uri = $route->uri();
        if (str_starts_with($uri, 'master/')) {
            $masterRoutes[] = $route;
        }
    }

    expect($masterRoutes)->not->toBeEmpty();

    foreach ($masterRoutes as $route) {
        $uri = $route->uri();
        $methods = implode('|', $route->methods());
        $middleware = $route->gatherMiddleware();

        // 1. Must be protected by auth middleware
        $hasAuth = in_array('auth', $middleware, true);
        expect($hasAuth)->toBeTrue("Route [{$methods}] {$uri} must have 'auth' middleware.");

        // 2. Must have explicit can: permission check
        $canMiddleware = array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'can:'));
        expect($canMiddleware)->not->toBeEmpty("Route [{$methods}] {$uri} must have explicit 'can:' authorization middleware.");
    }
});

test('unauthenticated guests are redirected to login for any master data route', function (): void {
    $guestTestRoutes = [
        ['GET', '/master/units'],
        ['POST', '/master/units'],
        ['GET', '/master/product-categories'],
        ['POST', '/master/product-categories'],
        ['GET', '/master/products'],
        ['POST', '/master/products'],
        ['GET', '/master/warehouses'],
        ['POST', '/master/warehouses'],
        ['GET', '/master/suppliers'],
        ['POST', '/master/suppliers'],
        ['GET', '/master/customers'],
        ['POST', '/master/customers'],
        ['GET', '/master/tax-rates'],
        ['POST', '/master/tax-rates'],
        ['GET', '/master/payment-terms'],
        ['POST', '/master/payment-terms'],
    ];

    foreach ($guestTestRoutes as [$method, $uri]) {
        $response = $this->call($method, $uri);
        $response->assertStatus(302);
        $response->assertRedirect('/login');
    }
});
