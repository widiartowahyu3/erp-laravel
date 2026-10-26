<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class SetUserActiveCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erp:user:set-active {email : Alamat email pengguna} {state : Status akun (on|off)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mengaktifkan (on) atau menonaktifkan (off) akun pengguna';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawEmail = (string) $this->argument('email');
        $normalizedEmail = strtolower(trim($rawEmail));

        $rawState = strtolower(trim((string) $this->argument('state')));
        if (! in_array($rawState, ['on', 'off'], true)) {
            error("Parameter state tidak valid. Gunakan 'on' atau 'off'.");

            return self::FAILURE;
        }

        $isActive = ($rawState === 'on');

        /** @var User|null $user */
        $user = User::withTrashed()->where('email', $normalizedEmail)->first();

        if ($user === null) {
            error("Pengguna dengan email [{$normalizedEmail}] tidak ditemukan.");

            return self::FAILURE;
        }

        $oldActive = (bool) $user->is_active;

        $user->forceFill([
            'is_active' => $isActive,
        ])->save();

        if (! $isActive) {
            // Delete active sessions when user is deactivated
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        $event = $isActive ? 'user.activated' : 'user.deactivated';

        AuditLogger::record(
            event: $event,
            auditableType: User::class,
            auditableId: (int) $user->id,
            old: ['is_active' => $oldActive],
            new: ['is_active' => $isActive],
            userId: null
        );

        $stateText = $isActive ? 'diaktifkan' : 'dinonaktifkan';
        info("Pengguna [{$normalizedEmail}] berhasil {$stateText}.");

        return self::SUCCESS;
    }
}
