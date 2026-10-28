<?php

declare(strict_types=1);

use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Middleware\EnforceSessionTimeouts;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Guest authentication routes
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

// Authenticated platform routes
Route::middleware([
    'auth',
    AuthenticateSession::class,
    EnforceSessionTimeouts::class,
    EnsureUserIsActive::class,
])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::get('/account/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/account/password', [PasswordController::class, 'update'])->name('password.update');
});

// Development styleguide route (strictly local only)
if (app()->environment('local')) {
    Route::middleware([
        'web',
        'auth',
        AuthenticateSession::class,
        EnforceSessionTimeouts::class,
        EnsureUserIsActive::class,
    ])->get('/dev/styleguide', function () {
        return Inertia::render('Dev/Styleguide');
    })->name('dev.styleguide');
}
