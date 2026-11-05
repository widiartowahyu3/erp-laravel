<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;

final class SalesSummaryReport
{
    /**
     * @throws BusinessRuleException
     */
    public function handle(ReportFilter $filter): ReportResult
    {
        [$from, $to] = $filter->period();

        return DocumentSummaryQuery::run($from, $to, [
            'orders' => 'sales_orders',
            'fulfilments' => 'delivery_orders',
            'fulfilment_date' => 'delivery_date',
            'fulfilment_key' => 'delivery_count',
            'invoices' => 'sales_invoices',
            'parties' => 'customers',
            'party_column' => 'customer_id',
        ]);
    }
}
