<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('styleguide route returns 404 in non-local environments', function () {
    // In testing/production environment, the route must not be registered at all
    $response = $this->get('/dev/styleguide');

    $response->assertNotFound();
});

test('styleguide route redirects unauthenticated guest to login in local environment', function () {
    $this->app->detectEnvironment(fn () => 'local');
    require base_path('routes/web.php');

    $response = $this->get('/dev/styleguide');

    $response->assertRedirect('/login');
});

test('styleguide route returns 200 and loads inertia styleguide component for authenticated user in local environment', function () {
    $this->app->detectEnvironment(fn () => 'local');
    require base_path('routes/web.php');

    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/dev/styleguide');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('Dev/Styleguide'));
});
