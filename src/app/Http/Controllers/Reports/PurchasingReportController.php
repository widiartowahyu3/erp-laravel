<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Requests\Reports\ReportFilterRequest;
use App\Services\Reports\PurchasingSummaryReport;
use App\Services\Reports\ReportExports;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchasingReportController extends ReportController
{
    public function __invoke(ReportFilterRequest $request, PurchasingSummaryReport $report, ReportExports $exports): Response|StreamedResponse
    {
        $filter = $request->reportFilter();
        $result = $report->handle($filter);

        return $request->wantsCsv()
            ? $exports->documentSummary('ringkasan-pembelian', $result, $filter)
            : $this->page('Reports/PurchasingSummary', $result, $filter);
    }
}
