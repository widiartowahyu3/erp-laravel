<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\DocumentType;
use App\Support\Concerns\RequiresTransaction;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class DocumentNumberService
{
    use RequiresTransaction;

    /**
     * Generate a gapless document number with atomic single-statement increment inside an active transaction.
     * Format: {PREFIX}/{YYYYMM}/{NNNNN} (e.g., PO/202610/00001).
     *
     * @throws \LogicException If called outside an active transaction.
     */
    public static function generate(
        DocumentType $type,
        CarbonInterface|string $documentDate,
        ?string $connection = null
    ): string {
        self::ensureInTransaction($connection);

        $date = $documentDate instanceof CarbonInterface
            ? $documentDate
            : Carbon::parse($documentDate);

        $period = $date->format('Ym');
        $prefix = $type->prefix();

        $db = DB::connection($connection);
        $now = now();

        $affected = $db->affectingStatement(
            'INSERT INTO document_sequences (doc_type, period, prefix, last_number, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?) ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1), updated_at = VALUES(updated_at)',
            [$type->value, $period, $prefix, $now, $now]
        );

        $sequenceNumber = $affected === 1 ? 1 : (int) $db->selectOne('SELECT LAST_INSERT_ID() as num')->num;

        $paddedNumber = str_pad((string) $sequenceNumber, 5, '0', STR_PAD_LEFT);

        return "{$prefix}/{$period}/{$paddedNumber}";
    }
}
