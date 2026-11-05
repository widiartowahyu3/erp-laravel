<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Journal;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\Gate;

/**
 * Maps a journal's source code to its Indonesian label and document URL.
 */
final class JournalSourceResolver
{
    /**
     * @var array<string, array{label: string, route: string}>
     */
    private const array SOURCES = [
        StockService::SOURCE_GOODS_RECEIPT => ['label' => 'Penerimaan Barang', 'route' => 'purchasing.goods-receipts.show'],
        StockService::SOURCE_DELIVERY_ORDER => ['label' => 'Surat Jalan', 'route' => 'sales.delivery-orders.show'],
        StockService::SOURCE_STOCK_ADJUSTMENT => ['label' => 'Penyesuaian Stok', 'route' => 'inventory.stock-adjustments.show'],
        SalesInvoice::JOURNAL_SOURCE => ['label' => 'Faktur Penjualan', 'route' => 'sales.invoices.show'],
        Payment::JOURNAL_SOURCE => ['label' => 'Pembayaran', 'route' => 'accounting.payments.show'],
    ];

    public static function label(?string $sourceType): ?string
    {
        if ($sourceType === null) {
            return null;
        }

        return self::SOURCES[$sourceType]['label'] ?? $sourceType;
    }

    public static function url(?string $sourceType, ?int $sourceId): ?string
    {
        if ($sourceType === null || $sourceId === null || ! isset(self::SOURCES[$sourceType])) {
            return null;
        }

        return route(self::SOURCES[$sourceType]['route'], $sourceId);
    }

    /**
     * The original journal of a source document, for viewers allowed to see journals.
     *
     * @return array{id: int, number: string, status: string}|null
     */
    public static function related(string $sourceType, int $sourceId): ?array
    {
        if (! Gate::allows('journals.view')) {
            return null;
        }

        $journal = Journal::query()
            ->select(['id', 'number', 'status'])
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNull('reversal_of_id')
            ->first();

        return $journal === null ? null : [
            'id' => $journal->id,
            'number' => $journal->number,
            'status' => $journal->status->value,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::SOURCES as $value => $source) {
            $options[] = ['value' => $value, 'label' => $source['label']];
        }

        return $options;
    }
}
