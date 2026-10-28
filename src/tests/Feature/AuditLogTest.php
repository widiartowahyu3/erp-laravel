<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AuthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    RateLimiter::clear('login_ip|127.0.0.1');
});

test('login success records audit log without sensitive data or raw email', function () {
    $email = fake()->unique()->safeEmail();
    RateLimiter::clear(AuthService::loginThrottleKey($email, '127.0.0.1'));

    $user = User::factory()->create([
        'email' => $email,
        'password' => 'Valid#Password123',
    ]);

    $this->post('/login', [
        'email' => $email,
        'password' => 'Valid#Password123',
    ]);

    $log = DB::table('audit_logs')
        ->where('event', 'login.success')
        ->where('auditable_id', $user->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->auditable_type)->toBe('auth');

    // Ensure raw email is not stored in new_values
    expect($log->new_values)->not->toContain($email);
    // Ensure email_hash is present
    expect($log->new_values)->toContain(hash('sha256', $email));
});

test('login failed records audit log with email hash and user id if available', function () {
    $email = fake()->unique()->safeEmail();
    RateLimiter::clear(AuthService::loginThrottleKey($email, '127.0.0.1'));

    $user = User::factory()->create([
        'email' => $email,
        'password' => 'Valid#Password123',
    ]);

    $this->post('/login', [
        'email' => $email,
        'password' => 'WrongPassword!',
    ]);

    $log = DB::table('audit_logs')
        ->where('event', 'login.failed')
        ->where('auditable_id', $user->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->new_values)->not->toContain($email);
    expect($log->new_values)->not->toContain('WrongPassword!');
    expect($log->new_values)->toContain(hash('sha256', $email));
});

test('login throttled records audit log with email hash and ip', function () {
    $email = fake()->unique()->safeEmail();
    $ip = '127.0.0.1';
    RateLimiter::clear(AuthService::loginThrottleKey($email, $ip));

    for ($i = 0; $i < 6; $i++) {
        $this->post('/login', [
            'email' => $email,
            'password' => 'WrongPassword!',
        ]);
    }

    $log = DB::table('audit_logs')
        ->where('event', 'login.throttled')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->new_values)->not->toContain($email);
    expect($log->new_values)->toContain(hash('sha256', $email));
});

test('logout records audit log with user id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout');

    $log = DB::table('audit_logs')
        ->where('event', 'logout')
        ->where('auditable_id', $user->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect((int) $log->user_id)->toBe((int) $user->id);
});

test('password changed records audit log without passwords or tokens', function () {
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create([
        'email' => $email,
        'password' => 'Current#Pass1234',
    ]);

    $this->actingAs($user)->put('/account/password', [
        'current_password' => 'Current#Pass1234',
        'password' => 'BrandNew#Pass999',
        'password_confirmation' => 'BrandNew#Pass999',
    ]);

    $log = DB::table('audit_logs')
        ->where('event', 'password.changed')
        ->where('auditable_id', $user->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->old_values)->toBeNull();
    expect($log->new_values)->toBeNull();
});

test('session expired records audit log', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    session(['login_time' => now()->subMinutes(500)->timestamp]);

    $this->get('/');

    $log = DB::table('audit_logs')
        ->where('event', 'session.expired')
        ->where('auditable_id', $user->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
});

test('access denied 403 records audit log with permission name', function () {
    Route::get('/audit-test-permission', function () {
        return 'ok';
    })->middleware(['web', 'auth', 'can:users.view']);

    $user = User::factory()->create();

    $this->actingAs($user)->get('/audit-test-permission');

    $log = DB::table('audit_logs')
        ->where('event', 'access.denied')
        ->where('auditable_id', $user->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->new_values)->toContain('users.view');
});

test('audit logger recursively sanitizes sensitive keys and strips raw email', function () {
    $payload = [
        'regular_field' => 'regular_value',
        'email' => 'secret_user@example.com',
        'password' => 'should_be_stripped',
        'token' => 'should_be_stripped',
        'api_key' => 'should_be_stripped',
        'client_secret' => 'should_be_stripped',
        'nested' => [
            'inner_password' => 'should_be_stripped',
            'inner_secret' => 'should_be_stripped',
            'valid_data' => 12345,
            'email' => 'nested@example.com',
        ],
    ];

    $sanitized = AuditLogger::sanitizePayload($payload);

    expect($sanitized)->toHaveKey('regular_field');
    expect($sanitized)->toHaveKey('email_hash');
    expect($sanitized['email_hash'])->toBe(hash('sha256', 'secret_user@example.com'));
    expect($sanitized)->not->toHaveKey('password');
    expect($sanitized)->not->toHaveKey('token');
    expect($sanitized)->not->toHaveKey('api_key');
    expect($sanitized)->not->toHaveKey('client_secret');
    expect($sanitized)->not->toHaveKey('email');

    expect($sanitized['nested'])->toHaveKey('valid_data');
    expect($sanitized['nested'])->toHaveKey('email_hash');
    expect($sanitized['nested'])->not->toHaveKey('inner_password');
    expect($sanitized['nested'])->not->toHaveKey('inner_secret');
    expect($sanitized['nested'])->not->toHaveKey('email');
});

test('audit failure does not break authentication flow and is logged to error log', function () {
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create([
        'email' => $email,
        'password' => 'Valid#Password123',
    ]);

    Log::shouldReceive('error')
        ->atLeast()->once()
        ->withArgs(function ($message) {
            return str_contains($message, 'Failed to record audit log');
        });

    // Mock DB::table('audit_logs') to throw an exception
    DB::shouldReceive('table')
        ->with('audit_logs')
        ->andThrow(new RuntimeException('Simulated audit table failure'));

    // DB calls other than audit_logs must pass through
    DB::makePartial();

    // Call AuditLogger::record directly to verify exception handling and return false
    $result = AuditLogger::record(
        event: 'login.success',
        auditableType: 'auth',
        auditableId: (int) $user->id,
        new: ['email_hash' => hash('sha256', $email)]
    );

    expect($result)->toBeFalse();
});

test('consecutive throttled login requests produce at most one audit log row within 60 seconds', function () {
    $email = fake()->unique()->safeEmail();
    $ip = '127.0.0.1';
    RateLimiter::clear(AuthService::loginThrottleKey($email, $ip));
    RateLimiter::clear('login_ip|'.$ip);
    $emailHash = hash('sha256', $email);
    Cache::forget("audit_dedup|login.throttled|{$ip}");

    // Trigger throttling: first 5 fail, then 50 attempts while throttled
    for ($i = 0; $i < 55; $i++) {
        $this->post('/login', [
            'email' => $email,
            'password' => 'WrongPassword!',
        ]);
    }

    $count = DB::table('audit_logs')
        ->where('event', 'login.throttled')
        ->where('new_values', 'like', "%{$emailHash}%")
        ->count();

    expect($count)->toBe(1);
});

test('consecutive throttled login requests with rotating emails from same ip produce at most two audit log rows', function () {
    $ip = '198.51.100.99';
    RateLimiter::clear('login_ip|'.$ip);
    Cache::forget("audit_dedup|login.throttled|{$ip}");

    for ($i = 1; $i <= 50; $i++) {
        $email = "user{$i}_".uniqid().'@example.com';
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/login', [
            'email' => $email,
            'password' => 'WrongPassword!',
        ]);
    }

    $throttledCount = DB::table('audit_logs')
        ->where('event', 'login.throttled')
        ->where('ip_address', $ip)
        ->count();

    expect($throttledCount)->toBeGreaterThanOrEqual(1)
        ->and($throttledCount)->toBeLessThanOrEqual(2);
});

test('consecutive 403 access denied requests produce at most one audit log row within 60 seconds', function () {
    Route::get('/audit-test-dedup-403', function () {
        return 'ok';
    })->middleware(['web', 'auth', 'can:users.view']);

    $user = User::factory()->create();
    $ip = '127.0.0.1';
    Cache::forget("audit_dedup|access.denied|{$user->id}|users.view|{$ip}");

    for ($i = 0; $i < 50; $i++) {
        $this->actingAs($user)->get('/audit-test-dedup-403');
    }

    $count = DB::table('audit_logs')
        ->where('event', 'access.denied')
        ->where('auditable_id', $user->id)
        ->count();

    expect($count)->toBe(1);
});
