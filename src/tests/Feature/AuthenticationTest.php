<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseTransactions::class);

beforeEach(function () {
    RateLimiter::clear('login_ip|127.0.0.1');
});

test('guests accessing protected routes are redirected to login', function () {
    $this->get('/')->assertRedirect('/login');
    $this->get('/account/password')->assertRedirect('/login');
});

test('guests can view login page', function () {
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

test('successful login redirects to home and regenerates session id', function () {
    $email = fake()->unique()->safeEmail();
    RateLimiter::clear("login|{$email}|127.0.0.1");

    $user = User::factory()->create([
        'email' => $email,
        'password' => 'Secure#Pass1234',
        'is_active' => true,
    ]);

    $this->get('/login');
    $initialSessionId = session()->getId();

    $response = $this->post('/login', [
        'email' => strtoupper($email),
        'password' => 'Secure#Pass1234',
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($initialSessionId);
    expect(session()->get('login_time'))->not->toBeNull();

    $user->refresh();
    expect($user->last_login_at)->not->toBeNull();
});

test('wrong password, unknown email, inactive user, and soft-deleted user produce identical error messages and status', function () {
    $activeEmail = fake()->unique()->safeEmail();
    $inactiveEmail = fake()->unique()->safeEmail();
    $deletedEmail = fake()->unique()->safeEmail();
    $unknownEmail = fake()->unique()->safeEmail();

    RateLimiter::clear("login|{$activeEmail}|127.0.0.1");
    RateLimiter::clear("login|{$inactiveEmail}|127.0.0.1");
    RateLimiter::clear("login|{$deletedEmail}|127.0.0.1");
    RateLimiter::clear("login|{$unknownEmail}|127.0.0.1");

    $activeUser = User::factory()->create([
        'email' => $activeEmail,
        'password' => 'Valid#Password123',
        'is_active' => true,
    ]);

    $inactiveUser = User::factory()->create([
        'email' => $inactiveEmail,
        'password' => 'Valid#Password123',
        'is_active' => false,
    ]);

    $deletedUser = User::factory()->create([
        'email' => $deletedEmail,
        'password' => 'Valid#Password123',
        'is_active' => true,
    ]);
    $deletedUser->delete();

    $expectedMessage = 'Email atau kata sandi salah.';

    // 1. Unknown email
    $resUnknown = $this->from('/login')->post('/login', [
        'email' => $unknownEmail,
        'password' => 'Valid#Password123',
    ]);
    $resUnknown->assertRedirect('/login');
    $resUnknown->assertSessionHasErrors(['email' => $expectedMessage]);
    $this->assertGuest();

    // 2. Wrong password
    $resWrong = $this->from('/login')->post('/login', [
        'email' => $activeEmail,
        'password' => 'Wrong#Password999',
    ]);
    $resWrong->assertRedirect('/login');
    $resWrong->assertSessionHasErrors(['email' => $expectedMessage]);
    $this->assertGuest();

    // 3. Inactive user with correct password
    $resInactive = $this->from('/login')->post('/login', [
        'email' => $inactiveEmail,
        'password' => 'Valid#Password123',
    ]);
    $resInactive->assertRedirect('/login');
    $resInactive->assertSessionHasErrors(['email' => $expectedMessage]);
    $this->assertGuest();

    // 4. Soft-deleted user with correct password
    $resDeleted = $this->from('/login')->post('/login', [
        'email' => $deletedEmail,
        'password' => 'Valid#Password123',
    ]);
    $resDeleted->assertRedirect('/login');
    $resDeleted->assertSessionHasErrors(['email' => $expectedMessage]);
    $this->assertGuest();
});

test('rate limiting throttles after 5 consecutive failures and resets after success', function () {
    $email = fake()->unique()->safeEmail();
    $ip = '127.0.0.1';
    RateLimiter::clear("login|{$email}|{$ip}");

    for ($i = 0; $i < 5; $i++) {
        $response = $this->from('/login')->post('/login', [
            'email' => $email,
            'password' => 'WrongPassword123',
        ]);
        $response->assertRedirect('/login');
    }

    // 6th attempt should be throttled (HTTP 429)
    $response = $this->post('/login', [
        'email' => $email,
        'password' => 'WrongPassword123',
    ]);
    $response->assertStatus(429);
    $response->assertHeader('Retry-After');

    // Reset limiter and login with valid user
    RateLimiter::clear("login|{$email}|{$ip}");
    $user = User::factory()->create([
        'email' => $email,
        'password' => 'Valid#Password123',
        'is_active' => true,
    ]);

    $resSuccess = $this->post('/login', [
        'email' => $email,
        'password' => 'Valid#Password123',
    ]);
    $resSuccess->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

test('spoofed x-forwarded-for header does not alter client ip when remote_addr is private range', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.195'])
        ->get('/login');

    expect(request()->ip())->toBe('172.18.0.1');
});

test('login rate limiting cannot be bypassed with rotating x-forwarded-for headers', function () {
    $email = fake()->unique()->safeEmail();
    $remoteIp = '172.18.0.1';

    for ($i = 1; $i <= 5; $i++) {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $remoteIp])
            ->withHeaders(['X-Forwarded-For' => "198.51.100.{$i}"])
            ->from('/login')
            ->post('/login', [
                'email' => $email,
                'password' => "WrongPass{$i}!",
            ]);

        $response->assertRedirect('/login');
    }

    // 6th attempt with another spoofed X-Forwarded-For IP must trigger rate limiting
    $response = $this->withServerVariables(['REMOTE_ADDR' => $remoteIp])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.99'])
        ->from('/login')
        ->post('/login', [
            'email' => $email,
            'password' => 'WrongPass6!',
        ]);

    $response->assertStatus(429);
    $response->assertHeader('Retry-After');
});

test('GET logout returns 405 Method Not Allowed', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/logout');

    $response->assertStatus(405);
});

test('POST logout invalidates session and logs user out', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $response->assertRedirect('/login');
    $this->assertGuest();
});

test('absolute session timeout forces logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    // Simulate login 481 minutes ago (> 480 minutes absolute limit)
    session(['login_time' => now()->subMinutes(481)->timestamp]);

    $response = $this->get('/');

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors(['email' => 'Sesi Anda telah berakhir. Silakan masuk kembali.']);
    $this->assertGuest();
});

test('POST login without CSRF returns 419 when CSRF verification is enabled', function () {
    $this->app->bind(
        PreventRequestForgery::class,
        fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        }
    );

    $response = $this->post('/login', [
        'email' => 'test@example.com',
        'password' => 'Password#12345',
    ]);

    $response->assertStatus(419);
});

test('password change validates current password and rules', function () {
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create([
        'email' => $email,
        'password' => 'Current#Pass1234',
    ]);

    // 1. Wrong current password
    $resWrongCurrent = $this->actingAs($user)->from('/account/password')->put('/account/password', [
        'current_password' => 'IncorrectCurrentPwd',
        'password' => 'NewSecure#Pass999',
        'password_confirmation' => 'NewSecure#Pass999',
    ]);
    $resWrongCurrent->assertRedirect('/account/password');
    $resWrongCurrent->assertSessionHasErrors(['current_password' => 'Kata sandi saat ini tidak sesuai.']);

    // 2. Too short (< 12)
    $resShort = $this->actingAs($user)->from('/account/password')->put('/account/password', [
        'current_password' => 'Current#Pass1234',
        'password' => 'Short#1a',
        'password_confirmation' => 'Short#1a',
    ]);
    $resShort->assertSessionHasErrors('password');

    // 3. No uppercase
    $resNoUpper = $this->actingAs($user)->from('/account/password')->put('/account/password', [
        'current_password' => 'Current#Pass1234',
        'password' => 'lowercase#12345',
        'password_confirmation' => 'lowercase#12345',
    ]);
    $resNoUpper->assertSessionHasErrors('password');

    // 4. No lowercase
    $resNoLower = $this->actingAs($user)->from('/account/password')->put('/account/password', [
        'current_password' => 'Current#Pass1234',
        'password' => 'UPPERCASE#12345',
        'password_confirmation' => 'UPPERCASE#12345',
    ]);
    $resNoLower->assertSessionHasErrors('password');

    // 5. No number
    $resNoNum = $this->actingAs($user)->from('/account/password')->put('/account/password', [
        'current_password' => 'Current#Pass1234',
        'password' => 'NoNumberPassword#',
        'password_confirmation' => 'NoNumberPassword#',
    ]);
    $resNoNum->assertSessionHasErrors('password');

    // 6. Contains email local part
    $localPart = explode('@', $email)[0];
    $resEmailPart = $this->actingAs($user)->from('/account/password')->put('/account/password', [
        'current_password' => 'Current#Pass1234',
        'password' => 'Pass#'.$localPart.'1234',
        'password_confirmation' => 'Pass#'.$localPart.'1234',
    ]);
    $resEmailPart->assertSessionHasErrors('password');

    // 7. Same as current password
    $resSame = $this->actingAs($user)->from('/account/password')->put('/account/password', [
        'current_password' => 'Current#Pass1234',
        'password' => 'Current#Pass1234',
        'password_confirmation' => 'Current#Pass1234',
    ]);
    $resSame->assertSessionHasErrors('password');

    // 8. Successful change
    $resSuccess = $this->actingAs($user)->from('/account/password')->put('/account/password', [
        'current_password' => 'Current#Pass1234',
        'password' => 'ValidBrandNew#Pass999',
        'password_confirmation' => 'ValidBrandNew#Pass999',
    ]);
    $resSuccess->assertRedirect('/account/password');
    $resSuccess->assertSessionHas('status', 'Kata sandi berhasil diperbarui.');

    $user->refresh();
    expect(Hash::check('ValidBrandNew#Pass999', (string) $user->password))->toBeTrue();
});

test('password hashing uses argon2id', function () {
    $user = User::factory()->create([
        'password' => 'Argon2id#Password123',
    ]);

    $info = Hash::info((string) $user->password);

    expect($info['algoName'])->toBe('argon2id');
});

test('current password verification throttles after 5 consecutive failures', function () {
    $user = User::factory()->create([
        'password' => 'Initial#SecurePass123',
    ]);

    RateLimiter::clear("pwd_fail_user|{$user->id}");
    RateLimiter::clear('pwd_fail_ip|127.0.0.1');

    // 5 consecutive failed attempts
    for ($i = 1; $i <= 5; $i++) {
        $res = $this->actingAs($user)->from('/account/password')->put('/account/password', [
            'current_password' => "WrongCurrent#{$i}",
            'password' => 'New#SecurePass123',
            'password_confirmation' => 'New#SecurePass123',
        ]);
        $res->assertRedirect('/account/password');
        $res->assertSessionHasErrors('current_password');
    }

    // 6th attempt with CORRECT current password must be rejected with 429
    $resThrottled = $this->actingAs($user)->from('/account/password')->put('/account/password', [
        'current_password' => 'Initial#SecurePass123',
        'password' => 'New#SecurePass123',
        'password_confirmation' => 'New#SecurePass123',
    ]);
    $resThrottled->assertStatus(429);
    $resThrottled->assertHeader('Retry-After');
});

test('current password throttling is isolated between users', function () {
    $userA = User::factory()->create([
        'password' => 'UserA#SecurePass123',
    ]);
    $userB = User::factory()->create([
        'password' => 'UserB#SecurePass123',
    ]);

    RateLimiter::clear("pwd_fail_user|{$userA->id}");
    RateLimiter::clear("pwd_fail_user|{$userB->id}");
    RateLimiter::clear('pwd_fail_ip|192.0.2.1');
    RateLimiter::clear('pwd_fail_ip|192.0.2.2');

    // User A fails 5 times
    for ($i = 1; $i <= 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->actingAs($userA)
            ->from('/account/password')
            ->put('/account/password', [
                'current_password' => "WrongPass{$i}",
                'password' => 'BrandNew#Pass123',
                'password_confirmation' => 'BrandNew#Pass123',
            ]);
    }

    // User B on different IP should be able to update password successfully
    $this->flushSession();
    $resUserB = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
        ->actingAs($userB)
        ->from('/account/password')
        ->put('/account/password', [
            'current_password' => 'UserB#SecurePass123',
            'password' => 'BrandNew#Pass123',
            'password_confirmation' => 'BrandNew#Pass123',
        ]);

    $resUserB->assertRedirect('/account/password');
    $resUserB->assertSessionHas('status', 'Kata sandi berhasil diperbarui.');
});

test('successful password change resets current password failure counter', function () {
    $user = User::factory()->create([
        'password' => 'Initial#Pass12345',
    ]);

    RateLimiter::clear("pwd_fail_user|{$user->id}");
    RateLimiter::clear('pwd_fail_ip|127.0.0.1');

    // Fail 4 times (1 below throttle limit of 5)
    for ($i = 1; $i <= 4; $i++) {
        $this->actingAs($user)->from('/account/password')->put('/account/password', [
            'current_password' => "WrongPass{$i}",
            'password' => 'BrandNew#Pass999',
            'password_confirmation' => 'BrandNew#Pass999',
        ]);
    }

    // Now succeed with correct current password
    $resSuccess = $this->actingAs($user)->from('/account/password')->put('/account/password', [
        'current_password' => 'Initial#Pass12345',
        'password' => 'BrandNew#Pass999',
        'password_confirmation' => 'BrandNew#Pass999',
    ]);
    $resSuccess->assertRedirect('/account/password');

    // After success, user should be able to fail again without immediately hitting 429
    $resFailAgain = $this->actingAs($user)->from('/account/password')->put('/account/password', [
        'current_password' => 'WrongPassAgain',
        'password' => 'AnotherNew#Pass1',
        'password_confirmation' => 'AnotherNew#Pass1',
    ]);
    $resFailAgain->assertRedirect('/account/password');
    $resFailAgain->assertSessionHasErrors('current_password');
    expect(session('errors')->first('current_password'))->toBe('Kata sandi saat ini tidak sesuai.');
});

test('inertia login requests when throttled return redirect 303 with session errors', function () {
    $email = fake()->unique()->safeEmail();
    $ip = '127.0.0.1';
    RateLimiter::clear("login|{$email}|{$ip}");

    for ($i = 0; $i < 5; $i++) {
        $this->withHeader('X-Inertia', 'true')
            ->from('/login')
            ->post('/login', [
                'email' => $email,
                'password' => 'WrongPassword123',
            ]);
    }

    // 6th attempt with X-Inertia header must return 303 redirect with session error, NOT raw 429
    $response = $this->withHeader('X-Inertia', 'true')
        ->from('/login')
        ->post('/login', [
            'email' => $email,
            'password' => 'WrongPassword123',
        ]);

    $response->assertStatus(303);
    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Terlalu banyak percobaan');
});

test('inertia password update when throttled returns redirect 303 with session error', function () {
    $user = User::factory()->create([
        'password' => 'Current#Pass123',
    ]);

    RateLimiter::clear("pwd_fail_user|{$user->id}");
    RateLimiter::clear('pwd_fail_ip|127.0.0.1');

    for ($i = 0; $i < 5; $i++) {
        $this->withHeader('X-Inertia', 'true')
            ->actingAs($user)
            ->from('/account/password')
            ->put('/account/password', [
                'current_password' => 'WrongCurrentPass',
                'password' => 'New#ValidPass123',
                'password_confirmation' => 'New#ValidPass123',
            ]);
    }

    // 6th attempt with X-Inertia header must return 303 redirect with session error on current_password
    $response = $this->withHeader('X-Inertia', 'true')
        ->actingAs($user)
        ->from('/account/password')
        ->put('/account/password', [
            'current_password' => 'Current#Pass123',
            'password' => 'New#ValidPass123',
            'password_confirmation' => 'New#ValidPass123',
        ]);

    $response->assertStatus(303);
    $response->assertRedirect('/account/password');
    $response->assertSessionHasErrors('current_password');
    expect(session('errors')->first('current_password'))->toContain('Terlalu banyak percobaan');
});

test('non-inertia and json requests when throttled return 429 with retry-after header', function () {
    $email = fake()->unique()->safeEmail();
    $ip = '127.0.0.1';
    RateLimiter::clear("login|{$email}|{$ip}");

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', [
            'email' => $email,
            'password' => 'WrongPassword123',
        ]);
    }

    $response = $this->postJson('/login', [
        'email' => $email,
        'password' => 'WrongPassword123',
    ]);

    $response->assertStatus(429);
    $response->assertHeader('Retry-After');
    $response->assertJsonStructure(['message', 'errors' => ['email']]);
});
