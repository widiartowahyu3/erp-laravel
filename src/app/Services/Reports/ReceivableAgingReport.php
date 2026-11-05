<?php

declare(strict_types=1);

namespace App\Services\Reports;

final class ReceivableAgingReport
{
    public function handle(ReportFilter $filter): ReportResult
    {
        return AgingQuery::run('sales_invoices', 'customers', 'customer_id');
    }
}
