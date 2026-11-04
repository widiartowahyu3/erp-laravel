<?php

declare(strict_types=1);

namespace App\Services\Accounting\Builders;

use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Support\Accounting\JournalDraft;
use App\Support\Decimal;

/**
 * JT-SA: surplus and shortage are journaled per direction, each against inventory adjustment.
 */
final class StockAdjustmentJournalBuilder
{
    /**
     * @param  iterable<StockAdjustmentItem>  $items
     */
    public static function build(StockAdjustment $adjustment, iterable $items): JournalDraft
    {
        $surplus = '0.00';
        $shortage = '0.00';

        foreach ($items as $item) {
            if (Decimal::isNegative($item->difference_quantity)) {
                $quantity = Decimal::sub('0', $item->difference_quantity, Decimal::SCALE_QUANTITY);
                $shortage = Decimal::add($shortage, Decimal::mul($quantity, $item->unit_cost));
            } else {
                $surplus = Decimal::add($surplus, Decimal::mul($item->difference_quantity, $item->unit_cost));
            }
        }

        return (new JournalDraft("Penyesuaian stok {$adjustment->number}"))
            ->debit('inventory', $surplus, 'Selisih lebih')
            ->credit('inventory_adjustment', $surplus, 'Selisih lebih')
            ->debit('inventory_adjustment', $shortage, 'Selisih kurang')
            ->credit('inventory', $shortage, 'Selisih kurang');
    }
}
