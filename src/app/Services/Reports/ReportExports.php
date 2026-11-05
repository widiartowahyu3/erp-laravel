<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The columns and file name of each report's CSV. Financial statements flatten into section, account and amount.
 */
final class ReportExports
{
    private const array STATEMENT_COLUMNS = [
        ['key' => 'section', 'label' => 'Bagian'],
        ['key' => 'code', 'label' => 'Kode'],
        ['key' => 'name', 'label' => 'Nama'],
        ['key' => 'amount', 'label' => 'Jumlah', 'numeric' => true],
    ];

    private const array AGING_COLUMNS = [
        ['key' => 'party_code', 'label' => 'Kode'],
        ['key' => 'party_name', 'label' => 'Nama'],
        ['key' => 'not_due', 'label' => 'Belum Jatuh Tempo', 'numeric' => true],
        ['key' => 'days_1_30', 'label' => '1-30 Hari', 'numeric' => true],
        ['key' => 'days_31_60', 'label' => '31-60 Hari', 'numeric' => true],
        ['key' => 'days_61_90', 'label' => '61-90 Hari', 'numeric' => true],
        ['key' => 'days_over_90', 'label' => '> 90 Hari', 'numeric' => true],
        ['key' => 'total', 'label' => 'Total', 'numeric' => true],
    ];

    private const array SUMMARY_COLUMNS = [
        ['key' => 'party_code', 'label' => 'Kode'],
        ['key' => 'party_name', 'label' => 'Nama'],
        ['key' => 'invoice_count', 'label' => 'Jumlah Faktur', 'numeric' => true],
        ['key' => 'value', 'label' => 'Nilai', 'numeric' => true],
    ];

    private const array KIND_LABELS = ['opening' => 'Saldo Awal', 'invoice' => 'Faktur', 'payment' => 'Pembayaran'];

    public function __construct(private readonly CsvExporter $csv) {}

    /**
     * @throws BusinessRuleException
     */
    public function trialBalance(ReportResult $result, ReportFilter $filter): StreamedResponse
    {
        $rows = array_map(fn (array $row): array => [
            ...$row,
            'normal_label' => $row['normal_balance'] === 'debit' ? 'Debit' : 'Kredit',
        ], $result->rows);

        return $this->csv->download('neraca-saldo-'.implode('-', $filter->period()).'.csv', [
            ['key' => 'code', 'label' => 'Kode'],
            ['key' => 'name', 'label' => 'Nama'],
            ['key' => 'normal_label', 'label' => 'Saldo Normal'],
            ['key' => 'opening', 'label' => 'Saldo Awal', 'numeric' => true],
            ['key' => 'debit', 'label' => 'Debit', 'numeric' => true],
            ['key' => 'credit', 'label' => 'Kredit', 'numeric' => true],
            ['key' => 'closing', 'label' => 'Saldo Akhir', 'numeric' => true],
        ], $rows, count($rows));
    }

    /**
     * @throws BusinessRuleException
     */
    public function generalLedger(GeneralLedgerReport $report, ReportFilter $filter): StreamedResponse
    {
        return $this->csv->download('buku-besar-'.implode('-', $filter->period()).'.csv', [
            ['key' => 'journal_date', 'label' => 'Tanggal'],
            ['key' => 'journal_number', 'label' => 'Nomor Jurnal'],
            ['key' => 'description', 'label' => 'Deskripsi'],
            ['key' => 'source_label', 'label' => 'Sumber'],
            ['key' => 'debit', 'label' => 'Debit', 'numeric' => true],
            ['key' => 'credit', 'label' => 'Kredit', 'numeric' => true],
            ['key' => 'balance', 'label' => 'Saldo', 'numeric' => true],
        ], $report->exportRows($filter), $report->lineCount($filter));
    }

    /**
     * @throws BusinessRuleException
     */
    public function incomeStatement(ReportResult $result, ReportFilter $filter): StreamedResponse
    {
        $rows = $this->statementRows($result->rows);
        $rows[] = ['section' => 'Laba', 'code' => null, 'name' => 'Laba Kotor', 'amount' => $result->summary['gross_profit']];
        $rows[] = ['section' => 'Laba', 'code' => null, 'name' => 'Laba Bersih', 'amount' => $result->summary['net_profit']];

        return $this->csv->download('laba-rugi-'.implode('-', $filter->period()).'.csv', self::STATEMENT_COLUMNS, $rows, count($rows));
    }

    /**
     * @throws BusinessRuleException
     */
    public function balanceSheet(ReportResult $result): StreamedResponse
    {
        $rows = $this->statementRows($result->rows);
        $rows[] = ['section' => 'Total', 'code' => null, 'name' => 'Total Liabilitas dan Ekuitas', 'amount' => $result->summary['liabilities_and_equity']];

        return $this->csv->download("neraca-{$result->summary['as_of']}.csv", self::STATEMENT_COLUMNS, $rows, count($rows));
    }

    /**
     * @throws BusinessRuleException
     */
    public function aging(string $name, ReportResult $result): StreamedResponse
    {
        return $this->csv->download("{$name}-{$result->summary['as_of']}.csv", self::AGING_COLUMNS, $result->rows, count($result->rows));
    }

    /**
     * @throws BusinessRuleException
     */
    public function customerStatement(ReportResult $result, ReportFilter $filter): StreamedResponse
    {
        [$from] = $filter->period();
        $rows = array_map(
            fn (array $row): array => [...$row, 'kind_label' => self::KIND_LABELS[$row['kind']]],
            [['kind' => 'opening', 'entry_date' => $from, 'balance' => $result->summary['opening']], ...$result->rows]
        );

        return $this->csv->download('kartu-piutang-'.implode('-', $filter->period()).'.csv', [
            ['key' => 'entry_date', 'label' => 'Tanggal'],
            ['key' => 'kind_label', 'label' => 'Jenis'],
            ['key' => 'number', 'label' => 'Nomor'],
            ['key' => 'applied_to', 'label' => 'Untuk Faktur'],
            ['key' => 'debit', 'label' => 'Debit', 'numeric' => true],
            ['key' => 'credit', 'label' => 'Kredit', 'numeric' => true],
            ['key' => 'balance', 'label' => 'Saldo', 'numeric' => true],
        ], $rows, count($rows));
    }

    /**
     * @throws BusinessRuleException
     */
    public function inventoryReconciliation(ReportResult $result): StreamedResponse
    {
        $rows = array_map(fn (array $row): array => [
            'code' => $row['warehouse_code'],
            'name' => $row['warehouse_name'],
            'value' => $row['stock_value'],
        ], $result->rows);

        $rows[] = ['code' => null, 'name' => 'Total Nilai Persediaan', 'value' => $result->summary['stock_value']];
        $rows[] = ['code' => $result->summary['account_code'], 'name' => 'Saldo Akun Persediaan', 'value' => $result->summary['ledger_balance']];
        $rows[] = ['code' => null, 'name' => 'Selisih', 'value' => $result->summary['difference']];

        return $this->csv->download('rekonsiliasi-persediaan-'.now()->toDateString().'.csv', [
            ['key' => 'code', 'label' => 'Kode'],
            ['key' => 'name', 'label' => 'Keterangan'],
            ['key' => 'value', 'label' => 'Nilai', 'numeric' => true],
        ], $rows, count($rows));
    }

    /**
     * @throws BusinessRuleException
     */
    public function documentSummary(string $name, ReportResult $result, ReportFilter $filter): StreamedResponse
    {
        return $this->csv->download("{$name}-".implode('-', $filter->period()).'.csv', self::SUMMARY_COLUMNS, $result->rows, count($result->rows));
    }

    /**
     * @throws BusinessRuleException
     */
    public function inventorySummary(InventorySummaryReport $report, ReportFilter $filter): StreamedResponse
    {
        return $this->csv->download('ringkasan-persediaan-'.now()->toDateString().'.csv', [
            ['key' => 'warehouse_name', 'label' => 'Gudang'],
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'product_name', 'label' => 'Produk'],
            ['key' => 'quantity', 'label' => 'Stok', 'numeric' => true],
            ['key' => 'min_stock', 'label' => 'Minimum', 'numeric' => true],
            ['key' => 'below_minimum', 'label' => 'Di Bawah Minimum'],
        ], $report->exportRows($filter), $report->lineCount($filter));
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function statementRows(array $sections): array
    {
        $rows = [];

        foreach ($sections as $section) {
            foreach ($section['accounts'] as $account) {
                $rows[] = ['section' => $section['label'], 'code' => $account['code'], 'name' => $account['name'], 'amount' => $account['amount']];
            }

            if (array_key_exists('current_earnings', $section)) {
                $rows[] = ['section' => $section['label'], 'code' => null, 'name' => 'Laba Berjalan', 'amount' => $section['current_earnings']];
            }

            $rows[] = ['section' => $section['label'], 'code' => null, 'name' => "Total {$section['label']}", 'amount' => $section['total']];
        }

        return $rows;
    }
}
