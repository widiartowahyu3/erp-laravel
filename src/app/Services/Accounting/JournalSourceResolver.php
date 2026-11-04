<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Services\Inventory\StockService;

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
