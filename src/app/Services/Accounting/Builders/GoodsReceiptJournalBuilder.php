<?php

declare(strict_types=1);

namespace App\Services\Accounting\Builders;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Support\Accounting\JournalDraft;
use App\Support\Decimal;

/**
 * JT-GR: inventory against goods received not yet invoiced.
 */
final class GoodsReceiptJournalBuilder
{
    /**
     * @param  iterable<GoodsReceiptItem>  $items
     */
    public static function build(GoodsReceipt $receipt, iterable $items): JournalDraft
    {
        $total = '0.00';

        foreach ($items as $item) {
            $total = Decimal::add($total, Decimal::mul($item->quantity, $item->unit_cost));
        }

        return (new JournalDraft("Penerimaan barang {$receipt->number}"))
            ->debit('inventory', $total)
            ->credit('grir', $total);
    }
}
