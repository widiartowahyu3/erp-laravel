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

test('non-local environments strictly disallow unsafe-inline and unsafe-eval in csp', function () {
    // 1. Testing environment (non-local)
    $responseTesting = $this->get('/');
    $cspTesting = (string) $responseTesting->headers->get('Content-Security-Policy');

    expect($cspTesting)->toContain("style-src 'self'");
    expect($cspTesting)->not->toContain("'unsafe-inline'");
    expect($cspTesting)->not->toContain("'unsafe-eval'");
    $responseTesting->assertHeaderMissing('Strict-Transport-Security');

    // 2. Production environment (simulated non-local)
    app()->detectEnvironment(fn () => 'production');
    $responseProduction = $this->get('/');
    $cspProduction = (string) $responseProduction->headers->get('Content-Security-Policy');

    expect($cspProduction)->toContain("style-src 'self'");
    expect($cspProduction)->not->toContain("'unsafe-inline'");
    expect($cspProduction)->not->toContain("'unsafe-eval'");
    expect($cspProduction)->toContain('upgrade-insecure-requests');
    $responseProduction->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

    // Reset back to testing
    app()->detectEnvironment(fn () => 'testing');
});

test('local environment allows unsafe-inline for vite dev style injection without hsts', function () {
    app()->detectEnvironment(fn () => 'local');
    $responseLocal = $this->get('/');
    $cspLocal = (string) $responseLocal->headers->get('Content-Security-Policy');

    expect($cspLocal)->toContain("style-src 'self' 'unsafe-inline'");
    expect($cspLocal)->not->toContain("'unsafe-eval'");
    expect($cspLocal)->not->toContain('upgrade-insecure-requests');
    $responseLocal->assertHeaderMissing('Strict-Transport-Security');

    // Reset back to testing
    app()->detectEnvironment(fn () => 'testing');
});

test('no csp directive mixes unsafe-inline and nonce in any environment', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);
    $response = $this->get('/');
    $csp = (string) $response->headers->get('Content-Security-Policy');

    $directives = array_filter(array_map('trim', explode(';', $csp)));
    foreach ($directives as $directive) {
        $hasUnsafeInline = str_contains($directive, "'unsafe-inline'");
        $hasNonce = str_contains($directive, "'nonce-");

        expect($hasUnsafeInline && $hasNonce)
            ->toBeFalse("CSP directive [{$directive}] in [{$environment}] environment must NOT mix 'unsafe-inline' and nonce. In CSP Level 2/3, browsers ignore 'unsafe-inline' if a nonce is present.");
    }

    // Reset back to testing
    app()->detectEnvironment(fn () => 'testing');
})->with([
    'local',
    'testing',
    'production',
]);
