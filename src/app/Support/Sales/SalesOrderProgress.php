<?php

declare(strict_types=1);

namespace App\Support\Sales;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrderItem;
use App\Support\Decimal;

final class SalesOrderProgress
{
    /**
     * Determine the appropriate status of a Sales Order based on line item delivery quantities.
     *
     * @param  iterable<int, SalesOrderItem|array{quantity: string, delivered_quantity?: string|null}>  $items
     */
    public static function statusFor(iterable $items, SalesOrderStatus $currentStatus): SalesOrderStatus
    {
        if ($currentStatus === SalesOrderStatus::CLOSED || $currentStatus === SalesOrderStatus::VOID) {
            return $currentStatus;
        }

        $itemCount = 0;
        $hasAnyDelivered = false;
        $allFullyDelivered = true;

        foreach ($items as $item) {
            $itemCount++;

            $qty = $item instanceof SalesOrderItem
                ? $item->quantity
                : (string) $item['quantity'];

            $delivQty = $item instanceof SalesOrderItem
                ? $item->delivered_quantity
                : (string) ($item['delivered_quantity'] ?? '0.0000');

            if (Decimal::cmp($delivQty, '0') > 0) {
                $hasAnyDelivered = true;
            }

            if (Decimal::cmp($delivQty, $qty) < 0) {
                $allFullyDelivered = false;
            }
        }

        if ($itemCount === 0) {
            return $currentStatus;
        }

        if (! $hasAnyDelivered) {
            if ($currentStatus === SalesOrderStatus::PARTIALLY_DELIVERED || $currentStatus === SalesOrderStatus::DELIVERED) {
                return SalesOrderStatus::APPROVED;
            }

            return $currentStatus;
        }

        if ($allFullyDelivered) {
            return SalesOrderStatus::DELIVERED;
        }

        return SalesOrderStatus::PARTIALLY_DELIVERED;
    }
}
