<?php

declare(strict_types=1);

namespace App\Support\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrderItem;
use App\Support\Decimal;

final class PurchaseOrderProgress
{
    /**
     * Determine the appropriate status of a Purchase Order based on line item receipt quantities.
     *
     * @param  iterable<int, PurchaseOrderItem|array{quantity: string, received_quantity?: string|null}>  $items
     */
    public static function statusFor(iterable $items, PurchaseOrderStatus $currentStatus): PurchaseOrderStatus
    {
        if ($currentStatus === PurchaseOrderStatus::CLOSED || $currentStatus === PurchaseOrderStatus::VOID) {
            return $currentStatus;
        }

        $itemCount = 0;
        $hasAnyReceived = false;
        $allFullyReceived = true;

        foreach ($items as $item) {
            $itemCount++;

            $qty = $item instanceof PurchaseOrderItem
                ? $item->quantity
                : (string) $item['quantity'];

            $recvQty = $item instanceof PurchaseOrderItem
                ? $item->received_quantity
                : (string) ($item['received_quantity'] ?? '0.0000');

            if (Decimal::cmp($recvQty, '0') > 0) {
                $hasAnyReceived = true;
            }

            if (Decimal::cmp($recvQty, $qty) < 0) {
                $allFullyReceived = false;
            }
        }

        if ($itemCount === 0) {
            return $currentStatus;
        }

        if (! $hasAnyReceived) {
            if ($currentStatus === PurchaseOrderStatus::PARTIALLY_RECEIVED || $currentStatus === PurchaseOrderStatus::RECEIVED) {
                return PurchaseOrderStatus::APPROVED;
            }

            return $currentStatus;
        }

        if ($allFullyReceived) {
            return PurchaseOrderStatus::RECEIVED;
        }

        return PurchaseOrderStatus::PARTIALLY_RECEIVED;
    }
}
