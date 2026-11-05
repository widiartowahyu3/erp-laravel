<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams rows to a CSV download that opens correctly in a spreadsheet and cannot run a formula from stored text.
 */
final class CsvExporter
{
    public const int MAX_ROWS = 50000;

    private const string BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    private const array FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(private readonly int $maxRows = self::MAX_ROWS) {}

    /**
     * Too many rows is refused up front, because a quietly shortened report would read as complete.
     *
     * @param  list<array{key: string, label: string, numeric?: bool}>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     *
     * @throws BusinessRuleException
     */
    public function download(string $filename, array $columns, iterable $rows, int $rowCount): StreamedResponse
    {
        if ($rowCount > $this->maxRows) {
            throw new BusinessRuleException('Data terlalu banyak untuk diekspor (maksimal 50.000 baris). Persempit filter.', 422, 'export');
        }

        return new StreamedResponse(function () use ($columns, $rows): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fwrite($output, self::BYTE_ORDER_MARK);
            $this->writeLine($output, array_column($columns, 'label'));

            foreach ($rows as $row) {
                $this->writeLine($output, array_map(
                    fn (array $column): string => $this->cell($row[$column['key']] ?? null, $column['numeric'] ?? false),
                    $columns
                ));
            }

            fclose($output);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  resource  $output
     * @param  list<string>  $cells
     */
    private function writeLine($output, array $cells): void
    {
        fputcsv($output, $cells, ',', '"', '', "\n");
    }

    private function cell(mixed $value, bool $numeric): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }

        $text = (string) $value;

        if ($numeric || $text === '') {
            return $text;
        }

        return in_array($text[0], self::FORMULA_STARTS, true) ? "'".$text : $text;
    }
}
