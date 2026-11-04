<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Models\User;
use App\Services\Administration\UserAdministrationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const ADMIN_SERVICE_PASSWORD = 'Valid#Password2026';

function insertSessionFor(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test',
        'payload' => 'dummy',
        'last_activity' => now()->timestamp,
    ]);
}

test('create stores a normalized, hashed user with roles and audits without the password', function (): void {
    $role = Role::where('name', 'viewer')->firstOrFail();

    $user = (new UserAdministrationService)->create('  Budi  ', '  BUDI@Example.TEST ', ADMIN_SERVICE_PASSWORD, [$role->id]);

    expect($user->name)->toBe('Budi')
        ->and($user->email)->toBe('budi@example.test')
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check(ADMIN_SERVICE_PASSWORD, (string) $user->password))->toBeTrue()
        ->and($user->roles()->pluck('roles.id')->all())->toBe([$role->id]);

    $audit = DB::table('audit_logs')->where('event', 'user.created')->where('auditable_id', $user->id)->first();

    expect($audit)->not->toBeNull()
        ->and($audit->new_values)->not->toContain(ADMIN_SERVICE_PASSWORD)
        ->and($audit->new_values)->not->toContain((string) $user->password)
        ->and($audit->new_values)->not->toContain('budi@example.test')
        ->and($audit->new_values)->toContain('email_hash');
});

test('create rejects a duplicate email regardless of case, including soft deleted users', function (): void {
    $service = new UserAdministrationService;
    $existing = User::factory()->create(['email' => 'sama@example.test']);

    try {
        $service->create('Dua', 'SAMA@example.test', ADMIN_SERVICE_PASSWORD, []);
        test()->fail('Expected BusinessRuleException was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(422)->and($e->field)->toBe('email');
    }

    $existing->delete();

    expect(fn () => $service->create('Tiga', 'sama@example.test', ADMIN_SERVICE_PASSWORD, []))
        ->toThrow(BusinessRuleException::class);
});

test('create enforces the password policy', function (string $password): void {
    try {
        (new UserAdministrationService)->create('Budi', 'kebijakan@example.test', $password, []);
        test()->fail('Expected BusinessRuleException was not thrown.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(422)->and($e->field)->toBe('password');
    }

    expect(User::where('email', 'kebijakan@example.test')->exists())->toBeFalse();
})->with(['Pendek#1', 'tanpahurufbesar123', 'TANPAHURUFKECIL123', 'TanpaAngkaSamaSekali']);

test('resetPassword changes the password, revokes sessions and audits without the password', function (): void {
    $user = User::factory()->create(['password' => 'Old#Password2026']);
    insertSessionFor($user, 'sess_reset_service');

    (new UserAdministrationService)->resetPassword($user, ADMIN_SERVICE_PASSWORD);

    expect(Hash::check(ADMIN_SERVICE_PASSWORD, (string) $user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    $audit = DB::table('audit_logs')->where('event', 'password.reset')->where('auditable_id', $user->id)->first();

    expect($audit)->not->toBeNull()->and((string) $audit->new_values)->not->toContain(ADMIN_SERVICE_PASSWORD);
});

test('resetPassword enforces the password policy and changes nothing on failure', function (): void {
    $user = User::factory()->create(['password' => 'Old#Password2026']);
    insertSessionFor($user, 'sess_reset_policy');

    expect(fn () => (new UserAdministrationService)->resetPassword($user, 'lemah'))
        ->toThrow(BusinessRuleException::class);

    expect(Hash::check('Old#Password2026', (string) $user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
});

test('setActive off revokes sessions, on keeps them, and both are audited', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    insertSessionFor($user, 'sess_active_service');
    $service = new UserAdministrationService;

    $service->setActive($user, false);

    expect($user->fresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('event', 'user.deactivated')->where('auditable_id', $user->id)->exists())->toBeTrue();

    insertSessionFor($user, 'sess_active_service_2');
    $service->setActive($user, true);

    expect($user->fresh()->is_active)->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('event', 'user.activated')->where('auditable_id', $user->id)->exists())->toBeTrue();
});

test('an actor unknown to the database rolls back the administration change', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $fake = new User;
    $fake->id = 999999999;
    Auth::setUser($fake);

    $thrown = false;

    try {
        (new UserAdministrationService)->setActive($user, false);
    } catch (\Throwable) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();

    expect($user->fresh()->is_active)->toBeTrue();
});
