<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Exceptions\BusinessRuleException;
use App\Services\Reports\CsvExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

function streamedBody(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function csvOutput(array $rows, ?CsvExporter $exporter = null): string
{
    return streamedBody(($exporter ?? new CsvExporter)->download('uji.csv', [
        ['key' => 'name', 'label' => 'Nama'],
        ['key' => 'amount', 'label' => 'Jumlah', 'numeric' => true],
    ], $rows, count($rows)));
}

test('the file starts with a byte order mark and a header row', function (): void {
    expect(csvOutput([]))->toBe("\xEF\xBB\xBFNama,Jumlah\n");
});

test('the response is a named csv download', function (): void {
    $response = (new CsvExporter)->download('neraca-saldo.csv', [['key' => 'a', 'label' => 'A']], [], 0);

    expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="neraca-saldo.csv"');
});

test('commas, quotes and line breaks stay inside their cell', function (): void {
    $output = csvOutput([['name' => "PT \"Maju\", Tbk\nCabang 1", 'amount' => '10.00']]);

    expect($output)->toBe("\xEF\xBB\xBFNama,Jumlah\n\"PT \"\"Maju\"\", Tbk\nCabang 1\",10.00\n");
});

test('a text cell that a spreadsheet would run as a formula gets a leading quote', function (string $value, string $expected): void {
    $lines = explode("\n", csvOutput([['name' => $value, 'amount' => '1.00']]));

    expect($lines[1])->toBe($expected.',1.00');
})->with([
    'equals' => ['=SUM(A1)', "'=SUM(A1)"],
    'plus' => ['+6281', "'+6281"],
    'minus' => ['-5', "'-5"],
    'at' => ['@cmd', "'@cmd"],
    'tab' => ["\tx", "\"'\tx\""],
]);

test('a carriage return at the start of a text cell is guarded too', function (): void {
    expect(csvOutput([['name' => "\rx", 'amount' => '1.00']]))->toContain("'\rx");
});

test('ordinary text and number columns come through unchanged', function (): void {
    $lines = explode("\n", csvOutput([
        ['name' => 'Kas', 'amount' => '-300.00'],
        ['name' => 'Bank', 'amount' => '+1.00'],
    ]));

    expect($lines[1])->toBe('Kas,-300.00')
        ->and($lines[2])->toBe('Bank,+1.00');
});

test('empty cells stay empty and yes or no are spelled out', function (): void {
    $lines = explode("\n", csvOutput([
        ['name' => null, 'amount' => null],
        ['name' => true, 'amount' => '1.00'],
        ['name' => false, 'amount' => '2.00'],
    ]));

    expect($lines[1])->toBe(',')
        ->and($lines[2])->toBe('Ya,1.00')
        ->and($lines[3])->toBe('Tidak,2.00');
});

test('rows can come from a generator, one at a time', function (): void {
    $rows = (function () {
        yield ['name' => 'A'];
        yield ['name' => 'B'];
    })();

    $output = streamedBody((new CsvExporter)->download('uji.csv', [['key' => 'name', 'label' => 'Nama']], $rows, 2));

    expect($output)->toBe("\xEF\xBB\xBFNama\nA\nB\n");
});

test('the limit is 50,000 rows and more is refused rather than cut off', function (): void {
    expect(CsvExporter::MAX_ROWS)->toBe(50000);

    $exporter = new CsvExporter;
    $columns = [['key' => 'a', 'label' => 'A']];

    expect($exporter->download('uji.csv', $columns, [], 50000)->getStatusCode())->toBe(200);

    try {
        $exporter->download('uji.csv', $columns, [], 50001);
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(422)
            ->and($e->getMessage())->toBe('Data terlalu banyak untuk diekspor (maksimal 50.000 baris). Persempit filter.');

        return;
    }

    test()->fail('Expected a BusinessRuleException.');
});
