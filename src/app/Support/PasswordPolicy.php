<?php

declare(strict_types=1);

namespace App\Support;

final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 128;

    /**
     * Get all violation messages for a password.
     *
     * @return list<string>
     */
    public static function errors(string $password, ?string $email = null): array
    {
        $errors = [];

        if (strlen($password) < self::MIN_LENGTH) {
            $errors[] = 'Kata sandi minimal 12 karakter.';
        }

        if (strlen($password) > self::MAX_LENGTH) {
            $errors[] = 'Kata sandi maksimal 128 karakter.';
        }

        if (preg_match('/[A-Z]/', $password) !== 1) {
            $errors[] = 'Kata sandi harus mengandung setidaknya satu huruf besar.';
        }

        if (preg_match('/[a-z]/', $password) !== 1) {
            $errors[] = 'Kata sandi harus mengandung setidaknya satu huruf kecil.';
        }

        if (preg_match('/[0-9]/', $password) !== 1) {
            $errors[] = 'Kata sandi harus mengandung setidaknya satu angka.';
        }

        if ($email !== null) {
            $parts = explode('@', $email);
            $localPart = strtolower(trim($parts[0]));

            // Only enforce local-part restriction when length is at least 4 chars to prevent false positives
            if (strlen($localPart) >= 4 && str_contains(strtolower($password), $localPart)) {
                $errors[] = 'Kata sandi tidak boleh memuat bagian email pengguna.';
            }
        }

        return $errors;
    }

    /**
     * Validate a password against the policy. Returns the first violation or null if valid.
     */
    public static function validate(string $password, ?string $email = null): ?string
    {
        $errors = self::errors($password, $email);

        return $errors[0] ?? null;
    }

    /**
     * Check if a password satisfies all policy requirements.
     */
    public static function isValid(string $password, ?string $email = null): bool
    {
        return self::validate($password, $email) === null;
    }
}
