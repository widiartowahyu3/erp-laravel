<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guest accessing home page is redirected to login', function () {
    $response = $this->get('/');

    $response->assertRedirect('/login');
});

test('authenticated user accessing home page returns 200 and loads inertia dashboard component', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('appName')
    );
});

test('health check endpoint up returns 200', function () {
    $response = $this->get('/up');

    $response->assertOk();
});
