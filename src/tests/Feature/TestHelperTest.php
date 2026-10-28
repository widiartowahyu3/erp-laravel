<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('userWithPermissions creates user with specified permissions and denies others', function (): void {
    $user = userWithPermissions(['users.view', 'products.view']);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->hasPermission('users.view'))->toBeTrue()
        ->and($user->hasPermission('products.view'))->toBeTrue()
        ->and($user->hasPermission('users.delete'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('users.view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('products.view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('users.delete'))->toBeFalse();
});

test('userWithRole attaches role and resolves role permissions properly', function (): void {
    $user = userWithRole('admin');

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->roles->pluck('name'))->toContain('admin')
        ->and($user->hasPermission('users.view'))->toBeTrue()
        ->and($user->hasPermission('purchase_orders.approve'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('users.view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('purchase_orders.approve'))->toBeFalse();
});

test('user without permissions is denied all abilities', function (): void {
    $user = User::factory()->create();

    expect($user->getPermissionNames())->toBeEmpty()
        ->and($user->hasPermission('users.view'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('users.view'))->toBeFalse()
        ->and(Gate::forUser($user)->denies('users.view'))->toBeTrue();
});
