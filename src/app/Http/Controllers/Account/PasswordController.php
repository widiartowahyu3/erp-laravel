<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the form for changing the user password.
     */
    public function edit(): Response
    {
        return Inertia::render('Account/Password');
    }

    /**
     * Update the user password.
     */
    public function update(UpdatePasswordRequest $request, AuthService $authService): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $authService->updatePassword($user, (string) $request->input('password'));

        return back()->with('status', 'Kata sandi berhasil diperbarui.');
    }
}
