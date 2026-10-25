<?php

declare(strict_types=1);

test('all required security headers are present in response', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
    $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    $response->assertHeader('Content-Security-Policy');
});

test('csp header contains unique nonce per request', function () {
    $response1 = $this->get('/');
    $response2 = $this->get('/');

    $csp1 = (string) $response1->headers->get('Content-Security-Policy');
    $csp2 = (string) $response2->headers->get('Content-Security-Policy');

    preg_match("/'nonce-([a-zA-Z0-9]+)'/", $csp1, $matches1);
    preg_match("/'nonce-([a-zA-Z0-9]+)'/", $csp2, $matches2);

    expect($matches1)->toHaveCount(2);
    expect($matches2)->toHaveCount(2);
    expect($matches1[1])->not->toBeEmpty();
    expect($matches2[1])->not->toBeEmpty();
    expect($matches1[1])->not->toBe($matches2[1]);
});

test('csp script-src directive strictly does not contain unsafe-eval', function () {
    $response = $this->get('/');
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->not->toContain("'unsafe-eval'");
});

test('upgrade-insecure-requests directive is present only in production', function () {
    // Current test environment
    $responseTesting = $this->get('/');
    $cspTesting = (string) $responseTesting->headers->get('Content-Security-Policy');
    expect($cspTesting)->not->toContain('upgrade-insecure-requests');

    // Simulated production environment
    app()->detectEnvironment(fn () => 'production');
    $responseProduction = $this->get('/');
    $cspProduction = (string) $responseProduction->headers->get('Content-Security-Policy');
    expect($cspProduction)->toContain('upgrade-insecure-requests');

    // Reset environment back to testing
    app()->detectEnvironment(fn () => 'testing');
});
