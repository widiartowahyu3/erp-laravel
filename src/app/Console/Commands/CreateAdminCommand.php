<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     * Note: Strictly interactive, passwords via arguments or options are forbidden.
     *
     * @var string
     */
    protected $signature = 'erp:admin:create';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Membuat pengguna administrator awal dengan role super_admin';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = text(
            label: 'Nama Admin:',
            placeholder: 'Wahyu Widiarto',
            required: true,
            validate: fn (string $v): ?string => strlen(trim($v)) > 0 && strlen($v) <= 100
                ? null
                : 'Nama wajib diisi dan maksimal 100 karakter.'
        );

        $email = text(
            label: 'Email Admin:',
            placeholder: 'admin@example.com',
            required: true,
            validate: fn (string $v): ?string => filter_var(trim($v), FILTER_VALIDATE_EMAIL)
                ? null
                : 'Format email tidak valid.'
        );

        $normalizedEmail = strtolower(trim($email));

        if (User::withTrashed()->where('email', $normalizedEmail)->exists()) {
            error("Email [{$normalizedEmail}] sudah terdaftar di sistem.");

            return self::FAILURE;
        }

        $emailParts = explode('@', $normalizedEmail);
        $localPart = $emailParts[0];

        $pwd = password(
            label: 'Kata Sandi:',
            required: true,
            validate: function (string $p) use ($localPart): ?string {
                if (strlen($p) < 12) {
                    return 'Kata sandi minimal 12 karakter.';
                }
                if (strlen($p) > 128) {
                    return 'Kata sandi maksimal 128 karakter.';
                }
                if (preg_match('/[A-Z]/', $p) !== 1) {
                    return 'Kata sandi harus mengandung setidaknya satu huruf besar.';
                }
                if (preg_match('/[a-z]/', $p) !== 1) {
                    return 'Kata sandi harus mengandung setidaknya satu huruf kecil.';
                }
                if (preg_match('/[0-9]/', $p) !== 1) {
                    return 'Kata sandi harus mengandung setidaknya satu angka.';
                }
                if ($localPart !== '' && str_contains(strtolower($p), strtolower($localPart))) {
                    return 'Kata sandi tidak boleh mengandung bagian email pengguna.';
                }

                return null;
            }
        );

        $pwdConfirm = password(
            label: 'Konfirmasi Kata Sandi:',
            required: true,
            validate: fn (string $c): ?string => $c === $pwd ? null : 'Konfirmasi kata sandi tidak cocok.'
        );

        $user = User::create([
            'name' => trim($name),
            'email' => $normalizedEmail,
            'password' => $pwd,
            'is_active' => true,
        ]);

        $superAdminRole = Role::where('name', 'super_admin')->first();
        if ($superAdminRole !== null) {
            $user->roles()->attach($superAdminRole->id, ['created_at' => now()]);
        }

        AuditLogger::record(
            event: 'user.created',
            auditableType: User::class,
            auditableId: (int) $user->id,
            old: null,
            new: [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email_hash' => hash('sha256', $normalizedEmail),
                'role' => 'super_admin',
            ],
            userId: null
        );

        info("Pengguna admin [{$normalizedEmail}] berhasil dibuat dengan role super_admin.");

        return self::SUCCESS;
    }
}
