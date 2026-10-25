<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('home page returns 200 and loads inertia dashboard component', function () {
    $response = $this->get('/');

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
