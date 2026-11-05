<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;

test('all report routes read only, need a login and name one report permission', function (): void {
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'reports/')) {
            $routes[] = $route;
        }
    }

    expect($routes)->toHaveCount(11);

    foreach ($routes as $route) {
        $uri = $route->uri();
        $middleware = $route->gatherMiddleware();

        expect(array_values(array_diff($route->methods(), ['GET', 'HEAD'])))->toBe([], "Route {$uri} must only read.")
            ->and(in_array('auth', $middleware, true))->toBeTrue("Route {$uri} must have 'auth' middleware.");

        $can = array_values(array_filter($middleware, fn ($m): bool => is_string($m) && str_starts_with($m, 'can:')));

        expect($can)->toHaveCount(1, "Route {$uri} must have one 'can:' middleware.")
            ->and($can[0])->toMatch('/^can:reports\.view_(finance|sales|purchasing|inventory)$/');
    }
});
