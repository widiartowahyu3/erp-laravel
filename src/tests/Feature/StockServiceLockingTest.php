<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Services\Inventory\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\TestDataFactory;
use Tests\Support\WithoutDatabaseTransactions;

uses(WithoutDatabaseTransactions::class);

test('increase and decrease throw LogicException when called outside of an active database transaction', function (): void {
    expect(DB::transactionLevel())->toBe(0);

    $service = new StockService;

    expect(fn () => $service->increase(1, 1, '10', '1000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1))
        ->toThrow(LogicException::class, 'This operation must be executed within an active database transaction.');

    expect(fn () => $service->decrease(1, 1, '5', StockMovementType::DELIVERY, 'delivery_order', 1))
        ->toThrow(LogicException::class, 'This operation must be executed within an active database transaction.');
});

test('two-connection locking test: concurrent connection blocks and times out with error 1205 then unblocks after rollback', function (): void {
    config(['database.connections.mariadb_second' => config('database.connections.mariadb')]);
    DB::purge('mariadb_second');

    // Connection A begins transaction, prepares product/warehouse, and locks balance via increase
    DB::connection('mariadb')->beginTransaction();
    try {
        $productId = TestDataFactory::createProduct(['track_stock' => true]);
        $warehouseId = TestDataFactory::createWarehouse();

        $service = new StockService;
        $service->increase($productId, $warehouseId, '10.0000', '1000.0000', StockMovementType::RECEIPT, StockService::SOURCE_GOODS_RECEIPT, 1);

        // Connection B configures 1 second timeout
        DB::connection('mariadb_second')->statement('SET innodb_lock_wait_timeout = 1');
        DB::connection('mariadb_second')->beginTransaction();

        $lockFailed = false;
        try {
            DB::connection('mariadb_second')->table('stock_balances')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();
        } catch (QueryException $e) {
            $lockFailed = true;
            expect($e->getMessage())->toMatch('/(Lock wait timeout exceeded|1205)/i')
                ->and($e->getMessage())->not->toMatch('/(Deadlock found|1213)/i');
        } finally {
            DB::connection('mariadb_second')->rollBack();
        }

        expect($lockFailed)->toBeTrue('Connection B should have failed due to lock wait timeout');
    } finally {
        // Rollback connection A to unblock and avoid persisting uncommitted stock data
        DB::connection('mariadb')->rollBack();
    }

    // After Connection A rolled back, Connection B is no longer blocked
    DB::connection('mariadb_second')->beginTransaction();
    try {
        $row = DB::connection('mariadb_second')->table('stock_balances')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        expect($row)->toBeNull();
    } finally {
        DB::connection('mariadb_second')->rollBack();
    }
});
