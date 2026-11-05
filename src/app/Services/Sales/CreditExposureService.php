<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Support\Decimal;

/**
 * Customer credit, checked only when a sales order is approved. Open orders and delivered
 * goods not yet invoiced are deliberately not counted (docs/SALES.md).
 */
final class CreditExposureService
{
    /**
     * Unpaid total of the customer's posted invoices.
     */
    public function outstanding(int $customerId): string
    {
        return $this->outstandingFor([$customerId])[$customerId] ?? '0.00';
    }

    /**
     * @param  list<int>  $customerIds
     * @return array<int, string> Only customers that owe something are present.
     */
    public function outstandingFor(array $customerIds): array
    {
        return SalesInvoice::query()
            ->whereIn('customer_id', $customerIds)
            ->whereIn('status', ['posted', 'partially_paid'])
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(total - paid_amount) as outstanding')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->customer_id => Decimal::round((string) $row->outstanding)])
            ->all();
    }

    /**
     * The customer row must already be locked by the caller. A limit of zero means no limit.
     *
     * @throws BusinessRuleException
     */
    public function assertWithinLimit(Customer $customer, string $orderTotal): void
    {
        $limit = (string) $customer->credit_limit;

        if (Decimal::cmp($limit, '0') <= 0) {
            return;
        }

        $outstanding = $this->outstanding($customer->id);

        if (Decimal::cmp(Decimal::add($outstanding, $orderTotal), $limit) > 0) {
            $limitText = Decimal::trim($limit);
            $owed = Decimal::trim($outstanding);
            $order = Decimal::trim($orderTotal);

            throw new BusinessRuleException(
                "Batas kredit pelanggan terlampaui (batas {$limitText}, piutang beredar {$owed}, pesanan ini {$order})",
                422,
                'customer_id'
            );
        }
    }
}
