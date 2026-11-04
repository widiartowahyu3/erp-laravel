<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Removes audit rows past the retention period. The period is also enforced by
 * the tr_audit_logs_before_delete trigger, so changing it needs a migration too.
 */
final class AuditRetentionService
{
    public const int RETENTION_DAYS = 7;

    private const int BATCH_SIZE = 1000;

    /**
     * Each batch and its audit record commit together, so rows never disappear unrecorded.
     *
     * @return int number of rows removed
     *
     * @throws Throwable
     */
    public function prune(): int
    {
        $total = 0;

        do {
            $deleted = DB::transaction(function (): int {
                $deleted = DB::table('audit_logs')
                    ->whereRaw('created_at < NOW() - INTERVAL 7 DAY')
                    ->orderBy('created_at')
                    ->limit(self::BATCH_SIZE)
                    ->delete();

                if ($deleted > 0) {
                    AuditLogger::record(
                        event: 'audit_log.pruned',
                        auditableType: AuditLog::class,
                        auditableId: 0,
                        new: ['deleted' => $deleted, 'retention_days' => self::RETENTION_DAYS],
                        strict: true
                    );
                }

                return $deleted;
            });

            $total += $deleted;
        } while ($deleted === self::BATCH_SIZE);

        return $total;
    }
}
