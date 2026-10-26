<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;

class ResetUserPasswordCommand extends Command
{
    /**
     * The name and signature of the console command.
     * Note: Email is passed as argument, password is strictly prompted interactively.
     *
     * @var string
     */
    protected $signature = 'erp:user:reset-password {email : Alamat email pengguna}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mereset kata sandi pengguna melalui prompt terminal interaktif';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawEmail = (string) $this->argument('email');
        $normalizedEmail = strtolower(trim($rawEmail));

        /** @var User|null $user */
        $user = User::withTrashed()->where('email', $normalizedEmail)->first();

        if ($user === null) {
            error("Pengguna dengan email [{$normalizedEmail}] tidak ditemukan.");

            return self::FAILURE;
        }

        $emailParts = explode('@', $normalizedEmail);
        $localPart = $emailParts[0];

        $pwd = password(
            label: "Kata Sandi Baru untuk [{$normalizedEmail}]:",
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
            label: 'Konfirmasi Kata Sandi Baru:',
            required: true,
            validate: fn (string $c): ?string => $c === $pwd ? null : 'Konfirmasi kata sandi tidak cocok.'
        );

        $user->forceFill([
            'password' => $pwd,
        ])->save();

        // Invalidate all active sessions for this user
        DB::table('sessions')->where('user_id', $user->id)->delete();

        AuditLogger::record(
            event: 'password.reset',
            auditableType: User::class,
            auditableId: (int) $user->id,
            old: null,
            new: ['email_hash' => hash('sha256', $normalizedEmail)],
            userId: null
        );

        info("Kata sandi pengguna [{$normalizedEmail}] berhasil direset dan seluruh sesi aktif telah dihapus.");

        return self::SUCCESS;
    }
}
