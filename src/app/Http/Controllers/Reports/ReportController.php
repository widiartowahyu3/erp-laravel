<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportFilter;
use App\Services\Reports\ReportResult;
use Inertia\Inertia;
use Inertia\Response;

abstract class ReportController extends Controller
{
    /**
     * @param  array<string, mixed>  $extra
     */
    protected function page(string $component, ?ReportResult $result, ReportFilter $filter, array $extra = []): Response
    {
        return Inertia::render($component, [
            'rows' => $result === null ? [] : $result->rows,
            'summary' => $result?->summary,
            'meta' => $result === null || $result->meta === [] ? null : $result->meta,
            'filters' => [
                'date_from' => $filter->dateFrom,
                'date_to' => $filter->dateTo,
                'as_of' => $filter->asOf,
                'account_id' => $filter->accountId,
                'customer_id' => $filter->customerId,
                'warehouse_id' => $filter->warehouseId,
                'below_minimum' => $filter->belowMinimumOnly,
                'per_page' => $filter->perPage,
            ],
            ...$extra,
        ]);
    }
}
