<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDataFactory;

uses(DatabaseTransactions::class);

test('negative stock_balances quantity is strictly rejected by check constraint', function () {
    $prodId = TestDataFactory::createProduct();
    $whId = TestDataFactory::createWarehouse();

    expect(function () use ($prodId, $whId) {
        DB::table('stock_balances')->insert([
            'product_id' => $prodId,
            'warehouse_id' => $whId,
            'quantity' => -5.0000,
            'avg_cost' => 1000.0000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});

test('invalid status on document tables is rejected by check constraint', function (string $table, array $recordData) {
    expect(function () use ($table, $recordData) {
        DB::table($table)->insert($recordData);
    })->toThrow(QueryException::class);
})->with([
    'purchase_orders' => fn () => [
        'purchase_orders',
        [
            'number' => 'PO/INVALID/'.uniqid(),
            'supplier_id' => TestDataFactory::createSupplier(),
            'warehouse_id' => TestDataFactory::createWarehouse(),
            'order_date' => now()->toDateString(),
            'status' => 'non_existent_status',
            'subtotal' => 10000.00,
            'total' => 10000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ],
    'goods_receipts' => fn () => [
        'goods_receipts',
        [
            'number' => 'GR/INVALID/'.uniqid(),
            'purchase_order_id' => TestDataFactory::createPurchaseOrder(),
            'supplier_id' => TestDataFactory::createSupplier(),
            'warehouse_id' => TestDataFactory::createWarehouse(),
            'receipt_date' => now()->toDateString(),
            'status' => 'non_existent_status',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ],
    'stock_adjustments' => fn () => [
        'stock_adjustments',
        [
            'number' => 'SA/INVALID/'.uniqid(),
            'warehouse_id' => TestDataFactory::createWarehouse(),
            'adjustment_date' => now()->toDateString(),
            'reason' => 'Audit check',
            'status' => 'invalid_status',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ],
    'sales_orders' => fn () => [
        'sales_orders',
        [
            'number' => 'SO/INVALID/'.uniqid(),
            'customer_id' => TestDataFactory::createCustomer(),
            'warehouse_id' => TestDataFactory::createWarehouse(),
            'order_date' => now()->toDateString(),
            'status' => 'invalid_status',
            'subtotal' => 10000.00,
            'total' => 10000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ],
    'delivery_orders' => fn () => [
        'delivery_orders',
        [
            'number' => 'DO/INVALID/'.uniqid(),
            'sales_order_id' => TestDataFactory::createSalesOrder(),
            'customer_id' => TestDataFactory::createCustomer(),
            'warehouse_id' => TestDataFactory::createWarehouse(),
            'delivery_date' => now()->toDateString(),
            'status' => 'invalid_status',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ],
    'journals' => fn () => [
        'journals',
        [
            'number' => 'JV/INVALID/'.uniqid(),
            'journal_date' => now()->toDateString(),
            'description' => 'Test journal',
            'status' => 'invalid_status',
            'total_debit' => 1000.00,
            'total_credit' => 1000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ],
    'sales_invoices' => fn () => [
        'sales_invoices',
        [
            'number' => 'SI/INVALID/'.uniqid(),
            'customer_id' => TestDataFactory::createCustomer(),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'invalid_status',
            'subtotal' => 10000.00,
            'total' => 10000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ],
    'purchase_invoices' => fn () => [
        'purchase_invoices',
        [
            'number' => 'PI/INVALID/'.uniqid(),
            'supplier_id' => TestDataFactory::createSupplier(),
            'supplier_invoice_number' => 'INV/'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'invalid_status',
            'subtotal' => 10000.00,
            'total' => 10000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ],
    'payments' => fn () => [
        'payments',
        [
            'number' => 'PAY/INVALID/'.uniqid(),
            'payment_type' => 'receipt',
            'customer_id' => TestDataFactory::createCustomer(),
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
            'account_id' => TestDataFactory::createAccount(),
            'amount' => 10000.00,
            'status' => 'invalid_status',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ],
]);

test('journal with unequal total_debit and total_credit is rejected by check constraint', function () {
    expect(function () {
        DB::table('journals')->insert([
            'number' => 'JV/UNBALANCED/'.uniqid(),
            'journal_date' => now()->toDateString(),
            'description' => 'Unbalanced test',
            'status' => 'draft',
            'total_debit' => 10000.00,
            'total_credit' => 9500.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});

test('journal line with both debit and credit positive is rejected by check constraint', function () {
    $journalId = DB::table('journals')->insertGetId([
        'number' => 'JV/LINE_TEST/'.uniqid(),
        'journal_date' => now()->toDateString(),
        'description' => 'Line test journal',
        'status' => 'draft',
        'total_debit' => 1000.00,
        'total_credit' => 1000.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $accId = TestDataFactory::createAccount();

    expect(function () use ($journalId, $accId) {
        DB::table('journal_lines')->insert([
            'journal_id' => $journalId,
            'line_no' => 1,
            'account_id' => $accId,
            'debit' => 500.00,
            'credit' => 500.00, // Both positive: forbidden by constraint
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});

test('payments without a party or with both customer and supplier are rejected by check constraint', function () {
    $accId = TestDataFactory::createAccount();

    // 1. Without party
    expect(function () use ($accId) {
        DB::table('payments')->insert([
            'number' => 'PAY/NOPARTY/'.uniqid(),
            'payment_type' => 'receipt',
            'customer_id' => null,
            'supplier_id' => null,
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
            'account_id' => $accId,
            'amount' => 50000.00,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);

    // 2. With both parties
    $custId = TestDataFactory::createCustomer();
    $supId = TestDataFactory::createSupplier();

    expect(function () use ($accId, $custId, $supId) {
        DB::table('payments')->insert([
            'number' => 'PAY/BOTHPARTIES/'.uniqid(),
            'payment_type' => 'receipt',
            'customer_id' => $custId,
            'supplier_id' => $supId,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'account_id' => $accId,
            'amount' => 50000.00,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});

test('payment allocations without target or with both sales and purchase invoices are rejected', function () {
    $payId = DB::table('payments')->insertGetId([
        'number' => 'PAY/ALLOC_TEST/'.uniqid(),
        'payment_type' => 'receipt',
        'customer_id' => TestDataFactory::createCustomer(),
        'payment_date' => now()->toDateString(),
        'method' => 'cash',
        'account_id' => TestDataFactory::createAccount(),
        'amount' => 50000.00,
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 1. Without target
    expect(function () use ($payId) {
        DB::table('payment_allocations')->insert([
            'payment_id' => $payId,
            'sales_invoice_id' => null,
            'purchase_invoice_id' => null,
            'amount' => 10000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);

    // 2. With both targets
    $siId = DB::table('sales_invoices')->insertGetId([
        'number' => 'SI/ALLOC/'.uniqid(),
        'customer_id' => TestDataFactory::createCustomer(),
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => 'draft',
        'subtotal' => 50000.00,
        'total' => 50000.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $supId = TestDataFactory::createSupplier();
    $piId = DB::table('purchase_invoices')->insertGetId([
        'number' => 'PI/ALLOC/'.uniqid(),
        'supplier_id' => $supId,
        'supplier_invoice_number' => 'SINV/'.uniqid(),
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => 'draft',
        'subtotal' => 50000.00,
        'total' => 50000.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(function () use ($payId, $siId, $piId) {
        DB::table('payment_allocations')->insert([
            'payment_id' => $payId,
            'sales_invoice_id' => $siId,
            'purchase_invoice_id' => $piId,
            'amount' => 10000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});

test('duplicate document numbers are rejected by unique constraint', function () {
    $whId = TestDataFactory::createWarehouse();
    $docNumber = 'SO/DUP/'.uniqid();

    TestDataFactory::createSalesOrder(['number' => $docNumber, 'warehouse_id' => $whId]);

    expect(function () use ($whId, $docNumber) {
        TestDataFactory::createSalesOrder(['number' => $docNumber, 'warehouse_id' => $whId]);
    })->toThrow(QueryException::class);
});

test('duplicate supplier invoice number per supplier is rejected by unique constraint', function () {
    $supId = TestDataFactory::createSupplier();
    $supInvNumber = 'INV-EXT-'.uniqid();

    DB::table('purchase_invoices')->insert([
        'number' => 'PI/1/'.uniqid(),
        'supplier_id' => $supId,
        'supplier_invoice_number' => $supInvNumber,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => 'draft',
        'subtotal' => 10000.00,
        'total' => 10000.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(function () use ($supId, $supInvNumber) {
        DB::table('purchase_invoices')->insert([
            'number' => 'PI/2/'.uniqid(),
            'supplier_id' => $supId,
            'supplier_invoice_number' => $supInvNumber,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 10000.00,
            'total' => 10000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});

test('deleting referenced master record is strictly blocked by RESTRICT foreign key', function () {
    $catId = TestDataFactory::createCategory();
    $prodId = TestDataFactory::createProduct(['category_id' => $catId]);

    expect(function () use ($catId) {
        DB::table('product_categories')->where('id', $catId)->delete();
    })->toThrow(QueryException::class);

    // Confirm category still exists
    expect(DB::table('product_categories')->where('id', $catId)->exists())->toBeTrue();
});

test('sales_invoices paid_amount exceeding total is rejected by check constraint', function () {
    expect(function () {
        DB::table('sales_invoices')->insert([
            'number' => 'SI/EXCEED/'.uniqid(),
            'customer_id' => TestDataFactory::createCustomer(),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 10000.00,
            'total' => 10000.00,
            'paid_amount' => 12000.00, // Exceeds total: rejected
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});

test('append-only triggers strictly reject UPDATE and DELETE on audit_logs and stock_movements', function () {
    // 1. Test audit_logs
    $auditId = DB::table('audit_logs')->insertGetId([
        'event' => 'created',
        'auditable_type' => 'App\\Models\\Test',
        'auditable_id' => 1,
        'old_values' => json_encode(['foo' => 'bar']),
        'created_at' => now(),
    ]);

    expect($auditId)->toBeGreaterThan(0);
    $audit = DB::table('audit_logs')->where('id', $auditId)->first();
    expect($audit)->not->toBeNull();

    // UPDATE must fail with 45000
    $updateAuditFailed = false;
    try {
        DB::table('audit_logs')->where('id', $auditId)->update(['event' => 'updated']);
    } catch (QueryException $e) {
        $updateAuditFailed = str_contains($e->getMessage(), 'audit_logs is append-only');
    }
    expect($updateAuditFailed)->toBeTrue('audit_logs UPDATE must be rejected by trigger');

    // DELETE must fail with 45000
    $deleteAuditFailed = false;
    try {
        DB::table('audit_logs')->where('id', $auditId)->delete();
    } catch (QueryException $e) {
        $deleteAuditFailed = str_contains($e->getMessage(), 'audit_logs is append-only');
    }
    expect($deleteAuditFailed)->toBeTrue('audit_logs DELETE must be rejected by trigger');

    // 2. Test stock_movements
    $prodId = TestDataFactory::createProduct();
    $whId = TestDataFactory::createWarehouse();

    $movementId = DB::table('stock_movements')->insertGetId([
        'movement_date' => now()->toDateTimeString(),
        'product_id' => $prodId,
        'warehouse_id' => $whId,
        'movement_type' => 'receipt',
        'quantity' => 10.0000,
        'unit_cost' => 5000.0000,
        'balance_after' => 10.0000,
        'avg_cost_after' => 5000.0000,
        'source_type' => 'goods_receipts',
        'source_id' => 1,
        'created_at' => now(),
    ]);

    expect($movementId)->toBeGreaterThan(0);
    $movement = DB::table('stock_movements')->where('id', $movementId)->first();
    expect($movement)->not->toBeNull();

    // UPDATE must fail with 45000
    $updateMovementFailed = false;
    try {
        DB::table('stock_movements')->where('id', $movementId)->update(['quantity' => 20.0000]);
    } catch (QueryException $e) {
        $updateMovementFailed = str_contains($e->getMessage(), 'stock_movements is append-only');
    }
    expect($updateMovementFailed)->toBeTrue('stock_movements UPDATE must be rejected by trigger');

    // DELETE must fail with 45000
    $deleteMovementFailed = false;
    try {
        DB::table('stock_movements')->where('id', $movementId)->delete();
    } catch (QueryException $e) {
        $deleteMovementFailed = str_contains($e->getMessage(), 'stock_movements is append-only');
    }
    expect($deleteMovementFailed)->toBeTrue('stock_movements DELETE must be rejected by trigger');
});

test('erp_app connection is strictly denied TRIGGER and ALTER privileges', function () {
    // Attempt CREATE TRIGGER
    $triggerFailed = false;
    try {
        DB::connection('mariadb')->statement('
            CREATE TRIGGER tr_forbidden BEFORE INSERT ON users FOR EACH ROW BEGIN END;
        ');
    } catch (Throwable $e) {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'command denied') || str_contains($msg, 'access denied') || str_contains($e->getMessage(), '42000')) {
            $triggerFailed = true;
        }
    }
    expect($triggerFailed)->toBeTrue('erp_app must NOT have TRIGGER privilege');

    // Attempt ALTER TABLE
    $alterFailed = false;
    try {
        DB::connection('mariadb')->statement('ALTER TABLE users ADD COLUMN forbidden_col VARCHAR(10)');
    } catch (Throwable $e) {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'command denied') || str_contains($msg, 'access denied') || str_contains($e->getMessage(), '42000')) {
            $alterFailed = true;
        }
    }
    expect($alterFailed)->toBeTrue('erp_app must NOT have ALTER privilege');
});
