<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

test('erp:admin:create command signature does not accept password via argument or option', function () {
    $command = Artisan::all()['erp:admin:create'];
    $definition = $command->getDefinition();

    expect($definition->hasArgument('password'))->toBeFalse();
    expect($definition->hasOption('password'))->toBeFalse();
});

test('erp:admin:create creates super_admin and rejects duplicates', function () {
    // 1. Create admin via prompts
    $this->artisan('erp:admin:create')
        ->expectsQuestion('Nama Admin:', 'Wahyu Admin')
        ->expectsQuestion('Email Admin:', 'wahyu.admin@example.com')
        ->expectsQuestion('Kata Sandi:', 'Admin#SecurePass2026')
        ->expectsQuestion('Konfirmasi Kata Sandi:', 'Admin#SecurePass2026')
        ->assertSuccessful();

    $user = User::where('email', 'wahyu.admin@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Wahyu Admin');
    expect($user->is_active)->toBeTrue();
    expect(Hash::check('Admin#SecurePass2026', (string) $user->password))->toBeTrue();
    expect($user->roles()->where('name', 'super_admin')->exists())->toBeTrue();

    // 2. Reject duplicate email
    $this->artisan('erp:admin:create')
        ->expectsQuestion('Nama Admin:', 'Another Admin')
        ->expectsQuestion('Email Admin:', 'wahyu.admin@example.com')
        ->assertFailed();
});

test('erp:admin:create fails atomically without creating users when super_admin role is missing', function () {
    // Delete super_admin role
    DB::table('permission_role')->whereIn('role_id', function ($query) {
        $query->select('id')->from('roles')->where('name', 'super_admin');
    })->delete();
    DB::table('roles')->where('name', 'super_admin')->delete();

    $email = 'missing.role@example.com';

    $this->artisan('erp:admin:create')
        ->expectsQuestion('Nama Admin:', 'No Role Admin')
        ->expectsQuestion('Email Admin:', $email)
        ->expectsQuestion('Kata Sandi:', 'Admin#SecurePass2026')
        ->expectsQuestion('Konfirmasi Kata Sandi:', 'Admin#SecurePass2026')
        ->assertFailed();

    expect(User::where('email', $email)->count())->toBe(0);
});

test('erp:user:reset-password resets password and invalidates user sessions', function () {
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create([
        'email' => $email,
        'password' => 'Old#Password123',
    ]);

    // Insert dummy session
    DB::table('sessions')->insert([
        'id' => 'session_test_reset_123',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
        'payload' => 'dummy',
        'last_activity' => now()->timestamp,
    ]);

    $this->artisan('erp:user:reset-password', ['email' => $email])
        ->expectsQuestion("Kata Sandi Baru untuk [{$email}]:", 'BrandNew#SecurePass2026')
        ->expectsQuestion('Konfirmasi Kata Sandi Baru:', 'BrandNew#SecurePass2026')
        ->assertSuccessful();

    $user->refresh();
    expect(Hash::check('BrandNew#SecurePass2026', (string) $user->password))->toBeTrue();

    // Verify session was removed
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('erp:user:set-active toggles active status and off invalidates user sessions', function () {
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create([
        'email' => $email,
        'is_active' => true,
    ]);

    // Insert dummy session
    DB::table('sessions')->insert([
        'id' => 'session_test_active_456',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
        'payload' => 'dummy',
        'last_activity' => now()->timestamp,
    ]);

    // Turn off
    $this->artisan('erp:user:set-active', ['email' => $email, 'state' => 'off'])
        ->assertSuccessful();

    $user->refresh();
    expect($user->is_active)->toBeFalse();
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    // Turn back on
    $this->artisan('erp:user:set-active', ['email' => $email, 'state' => 'on'])
        ->assertSuccessful();

    $user->refresh();
    expect($user->is_active)->toBeTrue();
});

test('erp:permissions:sync command executes seeders idempotently', function () {
    $this->artisan('erp:permissions:sync')
        ->assertSuccessful();

    expect(DB::table('permissions')->count())->toBe(98);
    expect(DB::table('roles')->count())->toBe(7);
    expect(DB::table('permission_role')->count())->toBe(275);
});
