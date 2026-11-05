<?php

declare(strict_types=1);

namespace App\Services\Reports;

final class PayableAgingReport
{
    public function handle(ReportFilter $filter): ReportResult
    {
        return AgingQuery::run('purchase_invoices', 'suppliers', 'supplier_id');
    }
}
