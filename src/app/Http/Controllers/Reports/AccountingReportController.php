<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Requests\Reports\ReportFilterRequest;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Services\Reports\BalanceSheetReport;
use App\Services\Reports\CustomerStatementReport;
use App\Services\Reports\GeneralLedgerReport;
use App\Services\Reports\IncomeStatementReport;
use App\Services\Reports\InventoryReconciliationReport;
use App\Services\Reports\PayableAgingReport;
use App\Services\Reports\ReceivableAgingReport;
use App\Services\Reports\ReportExports;
use App\Services\Reports\TrialBalanceReport;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountingReportController extends ReportController
{
    public function __construct(private readonly ReportExports $exports) {}

    public function trialBalance(ReportFilterRequest $request, TrialBalanceReport $report): Response|StreamedResponse
    {
        $filter = $request->reportFilter();
        $result = $report->handle($filter);

        return $request->wantsCsv()
            ? $this->exports->trialBalance($result, $filter)
            : $this->page('Reports/TrialBalance', $result, $filter);
    }

    public function generalLedger(ReportFilterRequest $request, GeneralLedgerReport $report): Response|StreamedResponse
    {
        $filter = $request->reportFilter();

        if ($request->wantsCsv()) {
            return $this->exports->generalLedger($report, $filter);
        }

        return $this->page('Reports/GeneralLedger', $filter->accountId === null ? null : $report->handle($filter), $filter, [
            'accounts' => ChartOfAccount::query()
                ->where('is_postable', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (ChartOfAccount $account): array => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name])
                ->all(),
        ]);
    }

    public function incomeStatement(ReportFilterRequest $request, IncomeStatementReport $report): Response|StreamedResponse
    {
        $filter = $request->reportFilter();
        $result = $report->handle($filter);

        return $request->wantsCsv()
            ? $this->exports->incomeStatement($result, $filter)
            : $this->page('Reports/IncomeStatement', $result, $filter);
    }

    public function balanceSheet(ReportFilterRequest $request, BalanceSheetReport $report): Response|StreamedResponse
    {
        $filter = $request->reportFilter();
        $result = $report->handle($filter);

        return $request->wantsCsv()
            ? $this->exports->balanceSheet($result)
            : $this->page('Reports/BalanceSheet', $result, $filter);
    }

    public function receivableAging(ReportFilterRequest $request, ReceivableAgingReport $report): Response|StreamedResponse
    {
        $filter = $request->reportFilter();
        $result = $report->handle($filter);

        return $request->wantsCsv()
            ? $this->exports->aging('umur-piutang', $result)
            : $this->page('Reports/ReceivableAging', $result, $filter);
    }

    public function payableAging(ReportFilterRequest $request, PayableAgingReport $report): Response|StreamedResponse
    {
        $filter = $request->reportFilter();
        $result = $report->handle($filter);

        return $request->wantsCsv()
            ? $this->exports->aging('umur-utang', $result)
            : $this->page('Reports/PayableAging', $result, $filter);
    }

    public function customerStatement(ReportFilterRequest $request, CustomerStatementReport $report): Response|StreamedResponse
    {
        $filter = $request->reportFilter();

        if ($request->wantsCsv()) {
            return $this->exports->customerStatement($report->handle($filter), $filter);
        }

        return $this->page('Reports/CustomerStatement', $filter->customerId === null ? null : $report->handle($filter), $filter, [
            'customers' => Customer::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'is_active'])
                ->map(fn (Customer $customer): array => [
                    'id' => $customer->id,
                    'code' => $customer->code,
                    'name' => $customer->name,
                    'is_active' => $customer->is_active,
                ])
                ->all(),
        ]);
    }

    public function inventoryReconciliation(ReportFilterRequest $request, InventoryReconciliationReport $report): Response|StreamedResponse
    {
        $filter = $request->reportFilter();
        $result = $report->handle($filter);

        return $request->wantsCsv()
            ? $this->exports->inventoryReconciliation($result)
            : $this->page('Reports/InventoryReconciliation', $result, $filter);
    }
}
