<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class AuthService
{
    /**
     * Constant dummy Argon2id hash for constant-time dummy verification.
     */
    private const DUMMY_ARGON2ID_HASH = '$argon2id$v=19$m=65536,t=4,p=1$UksxWGZmZE1UNDE0ZjM3Lg$jW8kZBUWp60BT4085QzlQVSvEqTwwkuE5m5pyksGQ+Q';

    /**
     * Attempt to authenticate a user with rate limiting and timing-attack mitigation.
     *
     * @throws ValidationException
     */
    public function login(string $email, string $password, Request $request): void
    {
        $normalizedEmail = strtolower(trim($email));
        $ip = (string) $request->ip();

        $emailIpKey = 'login|'.$normalizedEmail.'|'.$ip;
        $ipKey = 'login_ip|'.$ip;

        // 1. Rate Limiting Check (max 5 per min for email+ip, max 30 per min for ip)
        if (RateLimiter::tooManyAttempts($emailIpKey, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            $seconds = max(RateLimiter::availableIn($emailIpKey), RateLimiter::availableIn($ipKey));

            throw ValidationException::withMessages([
                'email' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
            ])->status(429);
        }

        // 2. Query user (including soft deleted to differentiate audit without exposing info to user)
        $user = User::withTrashed()->where('email', $normalizedEmail)->first();

        // 3. Timing-attack mitigation: if user not found, or inactive, or soft-deleted
        if ($user === null || ! $user->is_active || $user->trashed()) {
            Hash::check($password, self::DUMMY_ARGON2ID_HASH);

            RateLimiter::hit($emailIpKey, 60);
            RateLimiter::hit($ipKey, 60);

            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi salah.',
            ]);
        }

        // 4. Verify password
        if (! Hash::check($password, (string) $user->password)) {
            RateLimiter::hit($emailIpKey, 60);
            RateLimiter::hit($ipKey, 60);

            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi salah.',
            ]);
        }

        // 5. Successful login
        RateLimiter::clear($emailIpKey);

        Auth::login($user, false);
        $request->session()->regenerate();
        $request->session()->put('login_time', now()->timestamp);

        $user->forceFill(['last_login_at' => now()])->save();
    }

    /**
     * Invalidate session and log user out.
     */
    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Update user password, revoke other sessions, and record audit event.
     *
     * @throws ValidationException
     */
    public function updatePassword(User $user, string $newPassword): void
    {
        $rateLimitKey = 'password_change|'.$user->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            throw ValidationException::withMessages([
                'password' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
            ])->status(429);
        }

        RateLimiter::hit($rateLimitKey, 60);

        $user->forceFill([
            'password' => $newPassword,
        ])->save();

        Auth::logoutOtherDevices($newPassword);
    }
}
