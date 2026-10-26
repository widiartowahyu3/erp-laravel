<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Exceptions\TooManyAttemptsException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Validator;

class UpdatePasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Kata sandi saat ini wajib diisi.',
            'password.required' => 'Kata sandi baru wajib diisi.',
            'password.min' => 'Kata sandi baru minimal 12 karakter.',
            'password.max' => 'Kata sandi baru maksimal 128 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi baru tidak cocok.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->has('current_password')) {
                return;
            }

            $user = $this->user();
            if ($user === null) {
                return;
            }

            $userId = (int) $user->id;
            $ip = (string) $this->ip();
            $userKey = 'pwd_fail_user|'.$userId;
            $ipKey = 'pwd_fail_ip|'.$ip;

            // 1. Rate limiter check before current password verification
            if (RateLimiter::tooManyAttempts($userKey, 5) || RateLimiter::tooManyAttempts($ipKey, 20)) {
                $seconds = max(RateLimiter::availableIn($userKey), RateLimiter::availableIn($ipKey));

                throw new TooManyAttemptsException(
                    key: $userKey,
                    seconds: $seconds,
                    field: 'current_password',
                    message: "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik."
                );
            }

            $currentPassword = (string) $this->input('current_password');
            $newPassword = (string) $this->input('password');

            // 2. Verify current password
            if (! Hash::check($currentPassword, (string) $user->password)) {
                RateLimiter::hit($userKey, 60);
                RateLimiter::hit($ipKey, 60);

                $v->errors()->add('current_password', 'Kata sandi saat ini tidak sesuai.');

                return;
            }

            // 3. Uppercase letter requirement
            if (preg_match('/[A-Z]/', $newPassword) !== 1) {
                $v->errors()->add('password', 'Kata sandi baru harus mengandung setidaknya satu huruf besar.');
            }

            // 3. Lowercase letter requirement
            if (preg_match('/[a-z]/', $newPassword) !== 1) {
                $v->errors()->add('password', 'Kata sandi baru harus mengandung setidaknya satu huruf kecil.');
            }

            // 4. Number requirement
            if (preg_match('/[0-9]/', $newPassword) !== 1) {
                $v->errors()->add('password', 'Kata sandi baru harus mengandung setidaknya satu angka.');
            }

            // 5. Must not contain the local-part of the user's email
            $emailParts = explode('@', (string) $user->email);
            $localPart = strtolower($emailParts[0]);
            if ($localPart !== '' && str_contains(strtolower($newPassword), $localPart)) {
                $v->errors()->add('password', 'Kata sandi baru tidak boleh mengandung nama pengguna/email Anda.');
            }

            // 6. Must be different from the old password
            if (Hash::check($newPassword, (string) $user->password)) {
                $v->errors()->add('password', 'Kata sandi baru harus berbeda dari kata sandi saat ini.');
            }
        });
    }
}
