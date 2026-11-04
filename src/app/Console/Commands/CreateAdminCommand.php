<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Administration\UserAdministrationService;
use App\Support\PasswordPolicy;
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
    public function handle(UserAdministrationService $administration): int
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

        $pwd = password(
            label: 'Kata Sandi:',
            required: true,
            validate: fn (string $p): ?string => PasswordPolicy::validate($p, $normalizedEmail)
        );

        password(
            label: 'Konfirmasi Kata Sandi:',
            required: true,
            validate: fn (string $c): ?string => $c === $pwd ? null : 'Konfirmasi kata sandi tidak cocok.'
        );

        $superAdminRole = Role::where('name', 'super_admin')->first();
        if ($superAdminRole === null) {
            error('Jalankan make seed atau erp:permissions:sync terlebih dahulu.');

            return self::FAILURE;
        }

        $administration->create($name, $normalizedEmail, $pwd, [(int) $superAdminRole->id]);

        info("Pengguna admin [{$normalizedEmail}] berhasil dibuat dengan role super_admin.");

        return self::SUCCESS;
    }
}
