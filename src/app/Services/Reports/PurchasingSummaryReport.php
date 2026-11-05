<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;

final class PurchasingSummaryReport
{
    /**
     * @throws BusinessRuleException
     */
    public function handle(ReportFilter $filter): ReportResult
    {
        [$from, $to] = $filter->period();

        return DocumentSummaryQuery::run($from, $to, [
            'orders' => 'purchase_orders',
            'fulfilments' => 'goods_receipts',
            'fulfilment_date' => 'receipt_date',
            'fulfilment_key' => 'receipt_count',
            'invoices' => 'purchase_invoices',
            'parties' => 'suppliers',
            'party_column' => 'supplier_id',
        ]);
    }
}
