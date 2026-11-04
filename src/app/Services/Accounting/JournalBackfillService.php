<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\DeliveryOrderStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\StockAdjustmentStatus;
use App\Models\DeliveryOrder;
use App\Models\GoodsReceipt;
use App\Models\StockAdjustment;
use App\Services\Accounting\Builders\DeliveryOrderJournalBuilder;
use App\Services\Accounting\Builders\GoodsReceiptJournalBuilder;
use App\Services\Accounting\Builders\StockAdjustmentJournalBuilder;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\StockService;
use App\Support\Accounting\JournalDraft;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Creates journals for documents posted before the accounting module existed.
 * Voided documents net to zero and are skipped, as are documents that already have a journal.
 */
final class JournalBackfillService
{
    public function __construct(
        private readonly JournalService $journals = new JournalService
    ) {}

    /**
     * @return array{goods_receipt: int, delivery_order: int, stock_adjustment: int}
     */
    public function run(bool $execute): array
    {
        $counts = [
            StockService::SOURCE_GOODS_RECEIPT => $this->process(
                GoodsReceipt::query()->where('status', GoodsReceiptStatus::POSTED),
                StockService::SOURCE_GOODS_RECEIPT,
                fn (GoodsReceipt $document): JournalDraft => GoodsReceiptJournalBuilder::build($document, $document->items),
                fn (GoodsReceipt $document): string => (string) $document->getRawOriginal('receipt_date'),
                $execute
            ),
            StockService::SOURCE_DELIVERY_ORDER => $this->process(
                DeliveryOrder::query()->where('status', DeliveryOrderStatus::POSTED),
                StockService::SOURCE_DELIVERY_ORDER,
                fn (DeliveryOrder $document): JournalDraft => DeliveryOrderJournalBuilder::build($document, $document->items),
                fn (DeliveryOrder $document): string => (string) $document->getRawOriginal('delivery_date'),
                $execute
            ),
            StockService::SOURCE_STOCK_ADJUSTMENT => $this->process(
                StockAdjustment::query()->where('status', StockAdjustmentStatus::POSTED),
                StockService::SOURCE_STOCK_ADJUSTMENT,
                fn (StockAdjustment $document): JournalDraft => StockAdjustmentJournalBuilder::build($document, $document->items),
                fn (StockAdjustment $document): string => (string) $document->getRawOriginal('adjustment_date'),
                $execute
            ),
        ];

        if ($execute) {
            AuditLogger::record(
                event: 'journal.backfill',
                auditableType: 'journal',
                auditableId: 0,
                new: $counts,
                strict: true
            );
        }

        return $counts;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): JournalDraft  $buildDraft
     * @param  callable(TModel): string  $documentDate
     */
    private function process(Builder $query, string $sourceType, callable $buildDraft, callable $documentDate, bool $execute): int
    {
        $table = $query->getModel()->getTable();

        // Ids are read up front because writing journals shrinks this very result set
        $ids = (clone $query)
            ->whereNotExists(function (QueryBuilder $journals) use ($table, $sourceType): void {
                $journals->select('id')
                    ->from('journals')
                    ->where('source_type', $sourceType)
                    ->whereColumn('source_id', "{$table}.id")
                    ->whereNull('reversal_of_id');
            })
            ->orderBy('posted_at')
            ->orderBy('id')
            ->pluck('id');

        $count = 0;

        foreach ($ids as $id) {
            $document = (clone $query)->with('items')->findOrFail($id);
            $draft = $buildDraft($document);

            // A zero value document never gets a journal, so it is not counted or re-audited on every run
            if ($draft->isEmpty()) {
                continue;
            }

            if ($execute) {
                DB::transaction(fn () => $this->journals->postAutomatic(
                    $draft,
                    $sourceType,
                    (int) $id,
                    CarbonImmutable::parse($documentDate($document))
                ));
            }

            $count++;
        }

        return $count;
    }
}
