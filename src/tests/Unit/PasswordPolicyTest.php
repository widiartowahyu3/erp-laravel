<?php

declare(strict_types=1);

use App\Support\PasswordPolicy;

test('password policy validation rules', function (
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
})->with('password_policy_matrix');
