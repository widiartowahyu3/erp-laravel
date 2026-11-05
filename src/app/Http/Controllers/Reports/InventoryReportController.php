<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Requests\Reports\ReportFilterRequest;
use App\Models\Warehouse;
use App\Services\Reports\InventorySummaryReport;
use App\Services\Reports\ReportExports;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryReportController extends ReportController
{
    public function __invoke(ReportFilterRequest $request, InventorySummaryReport $report, ReportExports $exports): Response|StreamedResponse
    {
        $filter = $request->reportFilter();

        if ($request->wantsCsv()) {
            return $exports->inventorySummary($report, $filter);
        }

        return $this->page('Reports/InventorySummary', $report->handle($filter), $filter, [
            'warehouses' => Warehouse::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->map(fn (Warehouse $warehouse): array => ['id' => $warehouse->id, 'code' => $warehouse->code, 'name' => $warehouse->name])
                ->all(),
        ]);
    }
}
