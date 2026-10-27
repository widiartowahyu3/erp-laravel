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
     * Generate a gapless document number with row locking inside an active transaction.
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

        // Ensure the sequence record exists for this doc_type and period
        $db->table('document_sequences')->insertOrIgnore([
            'doc_type' => $type->value,
            'period' => $period,
            'prefix' => $prefix,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Lock the sequence row exclusively for the duration of the caller's transaction
        /** @var object{id: int|string, last_number: int|string}|null $sequence */
        $sequence = $db->table('document_sequences')
            ->where('doc_type', $type->value)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            throw new \RuntimeException("Failed to lock document sequence for [{$type->value}] period [{$period}].");
        }

        $nextNumber = ((int) $sequence->last_number) + 1;

        $db->table('document_sequences')
            ->where('id', $sequence->id)
            ->update([
                'last_number' => $nextNumber,
                'updated_at' => now(),
            ]);

        $paddedNumber = str_pad((string) $nextNumber, 5, '0', STR_PAD_LEFT);

        return "{$prefix}/{$period}/{$paddedNumber}";
    }
}
