<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;

class SyncPermissionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erp:permissions:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Menyinkronkan katalog 98 permission dan pemetaan peran bawaan sistem';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        info('Menyinkronkan katalog permissions dan roles...');

        $this->call(PermissionSeeder::class);
        $this->call(RoleSeeder::class);

        info('Sinkronisasi permission dan peran berhasil diselesaikan.');

        return self::SUCCESS;
    }
}
