<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audit\AuditRetentionService;
use Illuminate\Console\Command;

class PruneAuditLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erp:audit:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Menghapus log audit yang berumur lebih dari 7 hari';

    /**
     * Execute the console command.
     */
    public function handle(AuditRetentionService $retention): int
    {
        $this->info("Log audit yang dihapus: {$retention->prune()} baris.");

        return self::SUCCESS;
    }
}
