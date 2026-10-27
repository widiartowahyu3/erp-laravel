<?php

declare(strict_types=1);

use App\Enums\DocumentType;
use App\Services\Documents\DocumentNumberService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('generate throws LogicException when called outside of an active database transaction', function () {
    expect(DB::transactionLevel())->toBe(0);

    expect(fn () => DocumentNumberService::generate(DocumentType::PO, '2026-10-27'))
        ->toThrow(LogicException::class, 'This operation must be executed within an active database transaction.');
});

test('generate creates formatted document number with exact prefix, period, and zero-padded sequence', function () {
    DB::beginTransaction();
    try {
        $num1 = DocumentNumberService::generate(DocumentType::PO, '2026-10-27');
        expect($num1)->toBe('PO/202610/00001');

        $num2 = DocumentNumberService::generate(DocumentType::PO, Carbon::parse('2026-10-28'));
        expect($num2)->toBe('PO/202610/00002');
    } finally {
        DB::rollBack();
    }
});

test('sequence counter expands naturally beyond 5 digits when needed', function () {
    DB::beginTransaction();
    try {
        $period = '202610';
        DB::table('document_sequences')->insert([
            'doc_type' => DocumentType::PO->value,
            'period' => $period,
            'prefix' => 'PO',
            'last_number' => 99999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $number = DocumentNumberService::generate(DocumentType::PO, '2026-10-15');
        expect($number)->toBe('PO/202610/100000');
    } finally {
        DB::rollBack();
    }
});

test('sequences reset and isolate per document type and per period', function () {
    DB::beginTransaction();
    try {
        $poOct = DocumentNumberService::generate(DocumentType::PO, '2026-10-01');
        $soOct = DocumentNumberService::generate(DocumentType::SO, '2026-10-01');
        $poNov = DocumentNumberService::generate(DocumentType::PO, '2026-11-01');

        expect($poOct)->toBe('PO/202610/00001')
            ->and($soOct)->toBe('SO/202610/00001')
            ->and($poNov)->toBe('PO/202611/00001');
    } finally {
        DB::rollBack();
    }
});

test('gapless guarantee: transaction rollback does not burn sequence numbers', function () {
    // 1. First transaction generates 00001 and rolls back
    DB::beginTransaction();
    $rolledBackNumber = DocumentNumberService::generate(DocumentType::GR, '2026-10-27');
    expect($rolledBackNumber)->toBe('GR/202610/00001');
    DB::rollBack();

    // 2. Next transaction must receive the SAME sequence number 00001
    DB::beginTransaction();
    try {
        $reusedNumber = DocumentNumberService::generate(DocumentType::GR, '2026-10-27');
        expect($reusedNumber)->toBe('GR/202610/00001');

        // And next is 00002
        $nextNumber = DocumentNumberService::generate(DocumentType::GR, '2026-10-27');
        expect($nextNumber)->toBe('GR/202610/00002');
    } finally {
        DB::rollBack();
    }
});

test('row lock test: concurrent connection blocks and times out then acquires next number on commit', function () {
    // Setup second database connection
    config(['database.connections.mariadb_second' => config('database.connections.mariadb')]);
    DB::purge('mariadb_second');

    $docType = DocumentType::SI;
    $date = '2026-10-27';

    // Connection A begins transaction and locks the row
    DB::connection('mariadb')->beginTransaction();
    $numA = DocumentNumberService::generate($docType, $date, 'mariadb');
    expect($numA)->toBe('SI/202610/00001');

    // Connection B configures 1 second lock wait timeout
    DB::connection('mariadb_second')->statement('SET innodb_lock_wait_timeout = 1');
    DB::connection('mariadb_second')->beginTransaction();

    $lockFailed = false;
    try {
        // Connection B attempts to lock the same row; must fail with lock wait timeout
        DocumentNumberService::generate($docType, $date, 'mariadb_second');
    } catch (QueryException $e) {
        $lockFailed = true;
        expect($e->getMessage())->toMatch('/(Lock wait timeout exceeded|1205)/i');
    } finally {
        DB::connection('mariadb_second')->rollBack();
    }

    expect($lockFailed)->toBeTrue('Connection B should have failed due to lock wait timeout');

    // Connection A now commits
    DB::connection('mariadb')->commit();

    // Connection B can now acquire the lock and receives the next number (00002)
    DB::connection('mariadb_second')->beginTransaction();
    try {
        $numB = DocumentNumberService::generate($docType, $date, 'mariadb_second');
        expect($numB)->toBe('SI/202610/00002');
        DB::connection('mariadb_second')->commit();
    } catch (Throwable $e) {
        DB::connection('mariadb_second')->rollBack();
        throw $e;
    } finally {
        // Clean up created rows for this test
        DB::connection('mariadb')->table('document_sequences')
            ->where('doc_type', $docType->value)
            ->where('period', '202610')
            ->delete();
    }
});
