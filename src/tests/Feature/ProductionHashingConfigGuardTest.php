<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Config;

test('AppServiceProvider boot allows valid argon2id config in production', function () {
    $app = clone app();
    $app->detectEnvironment(fn () => 'production');

    Config::set('hashing.driver', 'argon2id');
    Config::set('hashing.argon.memory', 65536);
    Config::set('hashing.argon.time', 4);

    $provider = new AppServiceProvider($app);
    $provider->boot();

    expect(true)->toBeTrue();
});

test('AppServiceProvider boot throws exception in production when hash driver is not argon2id', function () {
    $app = clone app();
    $app->detectEnvironment(fn () => 'production');

    Config::set('hashing.driver', 'bcrypt');
    Config::set('hashing.argon.memory', 65536);
    Config::set('hashing.argon.time', 4);

    $provider = new AppServiceProvider($app);

    expect(fn () => $provider->boot())
        ->toThrow(RuntimeException::class, 'Konfigurasi hashing di lingkungan production tidak aman');
});

test('AppServiceProvider boot throws exception in production when argon memory is below 65536', function () {
    $app = clone app();
    $app->detectEnvironment(fn () => 'production');

    Config::set('hashing.driver', 'argon2id');
    Config::set('hashing.argon.memory', 32768);
    Config::set('hashing.argon.time', 4);

    $provider = new AppServiceProvider($app);

    expect(fn () => $provider->boot())
        ->toThrow(RuntimeException::class, 'Konfigurasi hashing di lingkungan production tidak aman');
});

test('AppServiceProvider boot throws exception in production when argon time is below 4', function () {
    $app = clone app();
    $app->detectEnvironment(fn () => 'production');

    Config::set('hashing.driver', 'argon2id');
    Config::set('hashing.argon.memory', 65536);
    Config::set('hashing.argon.time', 2);

    $provider = new AppServiceProvider($app);

    expect(fn () => $provider->boot())
        ->toThrow(RuntimeException::class, 'Konfigurasi hashing di lingkungan production tidak aman');
});
