<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

test('UpdatePasswordRequest enforces password policy with identical dataset', function (
    string $password,
    string $email,
    bool $isValid,
    ?string $expectedError
) {
    RateLimiter::clear('pwd_fail_ip|127.0.0.1');

    $user = User::factory()->create([
        'email' => $email,
        'password' => 'Initial#SecurePass123',
    ]);
    RateLimiter::clear("pwd_fail_user|{$user->id}");

    $response = $this->actingAs($user)->put('/account/password', [
        'current_password' => 'Initial#SecurePass123',
        'password' => $password,
        'password_confirmation' => $password,
    ]);

    if ($isValid) {
        $response->assertSessionHasNoErrors();
        $user->refresh();
        expect(Hash::check($password, (string) $user->password))->toBeTrue();
    } else {
        $response->assertSessionHasErrors('password');
        expect(session('errors')->get('password'))->toContain($expectedError);
    }
})->with('password_policy_matrix');

test('erp:admin:create enforces password policy with identical dataset', function (
    string $password,
    string $email,
    bool $isValid,
    ?string $expectedError
) {
    // Ensure clean state for this email
    DB::table('users')->where('email', $email)->delete();

    if ($isValid) {
        $this->artisan('erp:admin:create')
            ->expectsQuestion('Nama Admin:', 'Super Admin Test')
            ->expectsQuestion('Email Admin:', $email)
            ->expectsQuestion('Kata Sandi:', $password)
            ->expectsQuestion('Konfirmasi Kata Sandi:', $password)
            ->assertSuccessful();

        $user = User::where('email', $email)->first();
        expect($user)->not->toBeNull();
        expect(Hash::check($password, (string) $user->password))->toBeTrue();
    } else {
        $this->artisan('erp:admin:create')
            ->expectsQuestion('Nama Admin:', 'Super Admin Test')
            ->expectsQuestion('Email Admin:', $email)
            ->expectsQuestion('Kata Sandi:', $password)
            ->assertFailed();

        expect(User::where('email', $email)->exists())->toBeFalse();
    }
})->with('password_policy_matrix');

test('erp:user:reset-password enforces password policy with identical dataset', function (
    string $password,
    string $email,
    bool $isValid,
    ?string $expectedError
) {
    DB::table('users')->where('email', $email)->delete();

    $user = User::factory()->create([
        'email' => $email,
        'password' => 'Old#SecurePassword123',
    ]);

    if ($isValid) {
        $this->artisan('erp:user:reset-password', ['email' => $user->email])
            ->expectsQuestion("Kata Sandi Baru untuk [{$user->email}]:", $password)
            ->expectsQuestion('Konfirmasi Kata Sandi Baru:', $password)
            ->assertSuccessful();

        $user->refresh();
        expect(Hash::check($password, (string) $user->password))->toBeTrue();
    } else {
        $this->artisan('erp:user:reset-password', ['email' => $user->email])
            ->expectsQuestion("Kata Sandi Baru untuk [{$user->email}]:", $password)
            ->assertFailed();

        $user->refresh();
        expect(Hash::check('Old#SecurePassword123', (string) $user->password))->toBeTrue();
    }
})->with('password_policy_matrix');
