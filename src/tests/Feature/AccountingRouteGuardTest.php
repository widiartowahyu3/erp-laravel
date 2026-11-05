<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;

test('all accounting routes are protected by authentication, explicit can middleware, and rate limited on writes', function (): void {
    $accountingRoutes = [];

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'accounting/')) {
            $accountingRoutes[] = $route;
        }
    }

    expect($accountingRoutes)->toHaveCount(24);

    foreach ($accountingRoutes as $route) {
        $uri = $route->uri();
        $methods = implode('|', $route->methods());
        $middleware = $route->gatherMiddleware();

        expect(in_array('auth', $middleware, true))->toBeTrue("Route [{$methods}] {$uri} must have 'auth' middleware.");

        $canMiddleware = array_values(array_filter($middleware, fn ($m): bool => is_string($m) && str_starts_with($m, 'can:')));
        expect($canMiddleware)->toHaveCount(1, "Route [{$methods}] {$uri} must have one specific 'can:' middleware.");
        expect($canMiddleware[0])->toMatch('/^can:(journals|chart_of_accounts|payments)\.[a-z]+$/');

        if (count(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) > 0) {
            expect(in_array('throttle:erp-write', $middleware, true))->toBeTrue("Write route {$uri} must be rate limited.");
        }
    }
});

test('guests are redirected to login on accounting pages', function (string $uri): void {
    $this->get($uri)->assertRedirect('/login');
})->with(['/accounting/journals', '/accounting/journals/create', '/accounting/accounts', '/accounting/accounts/create']);
