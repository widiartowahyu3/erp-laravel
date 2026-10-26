<?php

declare(strict_types=1);

use App\Support\PasswordPolicy;

test('password policy length validation', function (string $password, bool $isValid, ?string $expectedError) {
    $error = PasswordPolicy::validate($password);

    if ($isValid) {
        expect($error)->toBeNull();
        expect(PasswordPolicy::isValid($password))->toBeTrue();
    } else {
        expect($error)->toBe($expectedError);
        expect(PasswordPolicy::isValid($password))->toBeFalse();
    }
})->with([
    'too short (11 chars)' => ['Short#Pass1', false, 'Kata sandi minimal 12 karakter.'],
    'minimum length (12 chars)' => ['SecurePass#1', true, null],
    'valid standard length (16 chars)' => ['Valid#Secure1234', true, null],
    'maximum length (128 chars)' => [str_repeat('A', 60).str_repeat('a', 60).'12345678', true, null],
    'too long (129 chars)' => [str_repeat('A', 60).str_repeat('a', 60).'123456789', false, 'Kata sandi maksimal 128 karakter.'],
]);

test('password policy character composition requirements', function (string $password, string $expectedError) {
    $error = PasswordPolicy::validate($password);

    expect($error)->toBe($expectedError);
    expect(PasswordPolicy::isValid($password))->toBeFalse();
})->with([
    'no uppercase' => ['lowercase#12345', 'Kata sandi harus mengandung setidaknya satu huruf besar.'],
    'no lowercase' => ['UPPERCASE#12345', 'Kata sandi harus mengandung setidaknya satu huruf kecil.'],
    'no numbers' => ['NoNumberPassword#', 'Kata sandi harus mengandung setidaknya satu angka.'],
]);

test('password policy email local-part validation enforces >= 4 characters check and ignores < 4 characters', function (
    string $password,
    string $email,
    bool $isValid,
    ?string $expectedError
) {
    $error = PasswordPolicy::validate($password, $email);

    if ($isValid) {
        expect($error)->toBeNull();
        expect(PasswordPolicy::isValid($password, $email))->toBeTrue();
    } else {
        expect($error)->toBe($expectedError);
        expect(PasswordPolicy::isValid($password, $email))->toBeFalse();
    }
})->with([
    'local-part >= 4 contained exact case' => [
        'Pass#wahyu1234',
        'wahyu@example.com',
        false,
        'Kata sandi tidak boleh memuat bagian email pengguna.',
    ],
    'local-part >= 4 contained mixed case' => [
        'Pass#Wahyu1234',
        'wahyu@example.com',
        false,
        'Kata sandi tidak boleh memuat bagian email pengguna.',
    ],
    'local-part >= 4 not contained' => [
        'Valid#Pass1234',
        'wahyu@example.com',
        true,
        null,
    ],
    'local-part < 4 contained (bob, length 3) should pass without false positive' => [
        'Valid#bobPass123',
        'bob@example.com',
        true,
        null,
    ],
    'local-part < 4 contained (a, length 1) should pass without false positive' => [
        'Valid#Pass1234a',
        'a@example.com',
        true,
        null,
    ],
]);
