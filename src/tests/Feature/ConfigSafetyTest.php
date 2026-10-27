<?php

declare(strict_types=1);

function parseEnvFile(string $filePath): array
{
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $data = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $data[trim($key)] = trim($value);
        }
    }

    return $data;
}

test('env example files are production safe without hardcoded secrets', function (string $envFile) {
    $path = base_path($envFile);
    expect(file_exists($path))->toBeTrue();

    $config = parseEnvFile($path);

    expect($config['APP_ENV'] ?? null)->toBe('production');
    expect($config['APP_DEBUG'] ?? null)->toBe('false');
    expect($config['APP_KEY'] ?? '')->toBe('');
    expect($config['LOG_CHANNEL'] ?? '')->toBe('stderr');
    expect($config['MAIL_MAILER'] ?? '')->toBe('log');

    expect($config['DB_PASSWORD'] ?? '')->toBe('change_this_to_erp_app_password');
    expect($config['HASH_DRIVER'] ?? '')->toBe('argon2id');
    expect($config['SESSION_ABSOLUTE_LIFETIME'] ?? '')->toBe('480');
    expect($config['TRUSTED_PROXIES'] ?? null)->toBe('');

    expect(array_key_exists('DB_ROOT_PASSWORD', $config))->toBeFalse();
    expect(array_key_exists('DB_MIGRATOR_USERNAME', $config))->toBeFalse();
    expect(array_key_exists('DB_MIGRATOR_PASSWORD', $config))->toBeFalse();
})->with([
    '.env.example',
    '.env.production.example',
]);

test('session configuration defaults enforce httponly and samesite lax', function () {
    expect(config('session.http_only'))->toBeTrue();
    expect(config('session.same_site'))->toBe('lax');
});

test('hash driver default is configured to argon2id', function () {
    expect(config('hashing.driver'))->toBe('argon2id');
});

test('composer configuration enforces strict dependency isolation and minimal allow plugins', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect(isset($composer['require']['laravel/sail']))->toBeFalse();
    expect(isset($composer['require-dev']['laravel/sail']))->toBeFalse();

    expect(isset($composer['require']['laravel/tinker']))->toBeFalse();
    expect(isset($composer['require-dev']['laravel/tinker']))->toBeTrue();

    expect($composer['config']['allow-plugins'])->toBe([
        'pestphp/pest-plugin' => true,
    ]);
});

test('npmrc configuration enforces ignore-scripts', function () {
    $npmrcPath = base_path('.npmrc');
    expect(file_exists($npmrcPath))->toBeTrue();

    $content = (string) file_get_contents($npmrcPath);
    expect($content)->toContain('ignore-scripts=true');
});

test('trusted proxies configuration is empty by default and does not trust private ranges', function () {
    $trustedProxies = config('trustedproxy.proxies') ?? config('app.trusted_proxies');

    expect($trustedProxies)->toBeEmpty();
    expect(empty(env('TRUSTED_PROXIES')))->toBeTrue();

    // Verify bootstrap/app.php does not hardcode private CIDRs
    $bootstrapApp = (string) file_get_contents(base_path('bootstrap/app.php'));
    expect($bootstrapApp)->not->toContain('10.0.0.0/8');
    expect($bootstrapApp)->not->toContain('172.16.0.0/12');
    expect($bootstrapApp)->not->toContain('192.168.0.0/16');
});

test('trusted proxy behavioral resolution respects config and rejects spoofed headers', function () {
    // (a) With TRUSTED_PROXIES empty (default), REMOTE_ADDR=172.18.0.1 and XFF=203.0.113.9 -> request()->ip() = 172.18.0.1
    config(['trustedproxy.proxies' => null]);
    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->get('/login');
    expect(request()->ip())->toBe('172.18.0.1');

    // (b) With trusted proxy set to 172.18.0.0/16, request from REMOTE_ADDR=172.18.0.1 with XFF=203.0.113.9 -> ip() = 203.0.113.9
    config(['trustedproxy.proxies' => ['172.18.0.0/16']]);
    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->get('/login');
    expect(request()->ip())->toBe('203.0.113.9');

    // (c) With trusted proxy set to 172.18.0.0/16, REMOTE_ADDR outside trusted range (198.51.100.7) -> XFF is ignored
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->get('/login');
    expect(request()->ip())->toBe('198.51.100.7');
});
