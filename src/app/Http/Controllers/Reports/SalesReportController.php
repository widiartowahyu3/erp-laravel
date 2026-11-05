<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Requests\Reports\ReportFilterRequest;
use App\Services\Reports\ReportExports;
use App\Services\Reports\SalesSummaryReport;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReportController extends ReportController
{
    public function __invoke(ReportFilterRequest $request, SalesSummaryReport $report, ReportExports $exports): Response|StreamedResponse
    {
        $filter = $request->reportFilter();
        $result = $report->handle($filter);

        return $request->wantsCsv()
            ? $exports->documentSummary('ringkasan-penjualan', $result, $filter)
            : $this->page('Reports/SalesSummary', $result, $filter);
    }
}
