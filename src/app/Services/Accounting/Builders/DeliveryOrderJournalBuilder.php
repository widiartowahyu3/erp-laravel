<?php

declare(strict_types=1);

namespace App\Services\Accounting\Builders;

use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderItem;
use App\Support\Accounting\JournalDraft;
use App\Support\Decimal;

/**
 * JT-DO: cost of goods sold against inventory, valued at the cost snapshot on each line.
 */
final class DeliveryOrderJournalBuilder
{
    /**
     * @param  iterable<DeliveryOrderItem>  $items
     */
    public static function build(DeliveryOrder $delivery, iterable $items): JournalDraft
    {
        $total = '0.00';

        foreach ($items as $item) {
            $total = Decimal::add($total, Decimal::mul($item->quantity, $item->unit_cost));
        }

        return (new JournalDraft("Pengiriman barang {$delivery->number}"))
            ->debit('cogs', $total)
            ->credit('inventory', $total);
    }
}
