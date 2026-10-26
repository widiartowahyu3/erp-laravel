<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConstraintTestHelper;
use Tests\Support\TestDataFactory;

uses(DatabaseTransactions::class);

test('negative stock_balances quantity is strictly rejected by check constraint', function () {
    $prodIdValid = TestDataFactory::createProduct();
    $prodIdInvalid = TestDataFactory::createProduct();
    $whId = TestDataFactory::createWarehouse();

    // Positive control: valid positive quantity succeeds
    $validInsert = DB::table('stock_balances')->insert([
        'product_id' => $prodIdValid,
        'warehouse_id' => $whId,
        'quantity' => 10.0000,
        'avg_cost' => 1000.0000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($validInsert)->toBeTrue();

    // Negative control: negative quantity fails with specific check constraint
    ConstraintTestHelper::assertConstraintFailure(function () use ($prodIdInvalid, $whId) {
        DB::table('stock_balances')->insert([
            'product_id' => $prodIdInvalid,
            'warehouse_id' => $whId,
            'quantity' => -5.0000,
            'avg_cost' => 1000.0000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }, 'chk_stock_balances_quantity');
});

test('invalid status on document tables is rejected by check constraint', function (string $table, array $validData, array $invalidData, string $constraintName) {
    // Positive control: valid insert with valid status succeeds
    $validId = DB::table($table)->insertGetId($validData);
    expect($validId)->toBeGreaterThan(0);

    // Negative control: invalid status is rejected by check constraint
    ConstraintTestHelper::assertConstraintFailure(function () use ($table, $invalidData) {
        DB::table($table)->insert($invalidData);
    }, $constraintName);
})->with([
    'purchase_orders' => function () {
        $supId = TestDataFactory::createSupplier();
        $whId = TestDataFactory::createWarehouse();

        return [
            'purchase_orders',
            [
                'number' => 'PO/VAL/'.bin2hex(random_bytes(6)),
                'supplier_id' => $supId,
                'warehouse_id' => $whId,
                'order_date' => now()->toDateString(),
                'status' => 'draft',
                'subtotal' => 10000.00,
                'total' => 10000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'number' => 'PO/INVAL/'.bin2hex(random_bytes(6)),
                'supplier_id' => $supId,
                'warehouse_id' => $whId,
                'order_date' => now()->toDateString(),
                'status' => 'non_existent_status',
                'subtotal' => 10000.00,
                'total' => 10000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'chk_purchase_orders_status',
        ];
    },
    'goods_receipts' => function () {
        $poId = TestDataFactory::createPurchaseOrder();
        $supId = TestDataFactory::createSupplier();
        $whId = TestDataFactory::createWarehouse();

        return [
            'goods_receipts',
            [
                'number' => 'GR/VAL/'.bin2hex(random_bytes(6)),
                'purchase_order_id' => $poId,
                'supplier_id' => $supId,
                'warehouse_id' => $whId,
                'receipt_date' => now()->toDateString(),
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'number' => 'GR/INVAL/'.bin2hex(random_bytes(6)),
                'purchase_order_id' => $poId,
                'supplier_id' => $supId,
                'warehouse_id' => $whId,
                'receipt_date' => now()->toDateString(),
                'status' => 'non_existent_status',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'chk_goods_receipts_status',
        ];
    },
    'stock_adjustments' => function () {
        $whId = TestDataFactory::createWarehouse();

        return [
            'stock_adjustments',
            [
                'number' => 'SA/VAL/'.bin2hex(random_bytes(6)),
                'warehouse_id' => $whId,
                'adjustment_date' => now()->toDateString(),
                'reason' => 'Stock opname valid',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'number' => 'SA/INVAL/'.bin2hex(random_bytes(6)),
                'warehouse_id' => $whId,
                'adjustment_date' => now()->toDateString(),
                'reason' => 'Audit check',
                'status' => 'invalid_status',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'chk_stock_adjustments_status',
        ];
    },
    'sales_orders' => function () {
        $custId = TestDataFactory::createCustomer();
        $whId = TestDataFactory::createWarehouse();

        return [
            'sales_orders',
            [
                'number' => 'SO/VAL/'.bin2hex(random_bytes(6)),
                'customer_id' => $custId,
                'warehouse_id' => $whId,
                'order_date' => now()->toDateString(),
                'status' => 'draft',
                'subtotal' => 10000.00,
                'total' => 10000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'number' => 'SO/INVAL/'.bin2hex(random_bytes(6)),
                'customer_id' => $custId,
                'warehouse_id' => $whId,
                'order_date' => now()->toDateString(),
                'status' => 'invalid_status',
                'subtotal' => 10000.00,
                'total' => 10000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'chk_sales_orders_status',
        ];
    },
    'delivery_orders' => function () {
        $soId = TestDataFactory::createSalesOrder();
        $custId = TestDataFactory::createCustomer();
        $whId = TestDataFactory::createWarehouse();

        return [
            'delivery_orders',
            [
                'number' => 'DO/VAL/'.bin2hex(random_bytes(6)),
                'sales_order_id' => $soId,
                'customer_id' => $custId,
                'warehouse_id' => $whId,
                'delivery_date' => now()->toDateString(),
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'number' => 'DO/INVAL/'.bin2hex(random_bytes(6)),
                'sales_order_id' => $soId,
                'customer_id' => $custId,
                'warehouse_id' => $whId,
                'delivery_date' => now()->toDateString(),
                'status' => 'invalid_status',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'chk_delivery_orders_status',
        ];
    },
    'journals' => function () {
        return [
            'journals',
            [
                'number' => 'JV/VAL/'.bin2hex(random_bytes(6)),
                'journal_date' => now()->toDateString(),
                'description' => 'Valid journal',
                'status' => 'draft',
                'total_debit' => 1000.00,
                'total_credit' => 1000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'number' => 'JV/INVAL/'.bin2hex(random_bytes(6)),
                'journal_date' => now()->toDateString(),
                'description' => 'Test journal',
                'status' => 'invalid_status',
                'total_debit' => 1000.00,
                'total_credit' => 1000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'chk_journals_status',
        ];
    },
    'sales_invoices' => function () {
        $custId = TestDataFactory::createCustomer();

        return [
            'sales_invoices',
            [
                'number' => 'SI/VAL/'.bin2hex(random_bytes(6)),
                'customer_id' => $custId,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'status' => 'draft',
                'subtotal' => 10000.00,
                'total' => 10000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'number' => 'SI/INVAL/'.bin2hex(random_bytes(6)),
                'customer_id' => $custId,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'status' => 'invalid_status',
                'subtotal' => 10000.00,
                'total' => 10000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'chk_sales_invoices_status',
        ];
    },
    'purchase_invoices' => function () {
        $supId = TestDataFactory::createSupplier();

        return [
            'purchase_invoices',
            [
                'number' => 'PI/VAL/'.bin2hex(random_bytes(6)),
                'supplier_id' => $supId,
                'supplier_invoice_number' => 'INV/VAL/'.bin2hex(random_bytes(6)),
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'status' => 'draft',
                'subtotal' => 10000.00,
                'total' => 10000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'number' => 'PI/INVAL/'.bin2hex(random_bytes(6)),
                'supplier_id' => $supId,
                'supplier_invoice_number' => 'INV/INVAL/'.bin2hex(random_bytes(6)),
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'status' => 'invalid_status',
                'subtotal' => 10000.00,
                'total' => 10000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'chk_purchase_invoices_status',
        ];
    },
    'payments' => function () {
        $custId = TestDataFactory::createCustomer();
        $accId = TestDataFactory::createAccount();

        return [
            'payments',
            [
                'number' => 'PAY/VAL/'.bin2hex(random_bytes(6)),
                'payment_type' => 'receipt',
                'customer_id' => $custId,
                'payment_date' => now()->toDateString(),
                'method' => 'cash',
                'account_id' => $accId,
                'amount' => 10000.00,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'number' => 'PAY/INVAL/'.bin2hex(random_bytes(6)),
                'payment_type' => 'receipt',
                'customer_id' => $custId,
                'payment_date' => now()->toDateString(),
                'method' => 'cash',
                'account_id' => $accId,
                'amount' => 10000.00,
                'status' => 'invalid_status',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'chk_payments_status',
        ];
    },
]);

test('journal with unequal total_debit and total_credit is rejected by check constraint', function () {
    // Positive control: balanced journal succeeds
    $validId = DB::table('journals')->insertGetId([
        'number' => 'JV/BAL/'.bin2hex(random_bytes(6)),
        'journal_date' => now()->toDateString(),
        'description' => 'Balanced test',
        'status' => 'draft',
        'total_debit' => 1000.00,
        'total_credit' => 1000.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($validId)->toBeGreaterThan(0);

    // Negative control: unbalanced journal fails with chk_journals_balance
    ConstraintTestHelper::assertConstraintFailure(function () {
        DB::table('journals')->insert([
            'number' => 'JV/UNBAL/'.bin2hex(random_bytes(6)),
            'journal_date' => now()->toDateString(),
            'description' => 'Unbalanced test',
            'status' => 'draft',
            'total_debit' => 10000.00,
            'total_credit' => 9500.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }, 'chk_journals_balance');
});

test('journal line with both debit and credit positive is rejected by check constraint', function () {
    $journalId = DB::table('journals')->insertGetId([
        'number' => 'JV/LINE/'.bin2hex(random_bytes(6)),
        'journal_date' => now()->toDateString(),
        'description' => 'Line test journal',
        'status' => 'draft',
        'total_debit' => 1000.00,
        'total_credit' => 1000.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $accId = TestDataFactory::createAccount();

    // Positive control: valid line with debit > 0 and credit = 0 succeeds
    $validLine = DB::table('journal_lines')->insert([
        'journal_id' => $journalId,
        'line_no' => 1,
        'account_id' => $accId,
        'debit' => 500.00,
        'credit' => 0.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($validLine)->toBeTrue();

    // Negative control: line with both positive debit and credit fails with chk_journal_lines_amounts
    ConstraintTestHelper::assertConstraintFailure(function () use ($journalId, $accId) {
        DB::table('journal_lines')->insert([
            'journal_id' => $journalId,
            'line_no' => 2,
            'account_id' => $accId,
            'debit' => 500.00,
            'credit' => 500.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }, 'chk_journal_lines_amounts');
});

test('payments without a party or with both customer and supplier are rejected by check constraint', function () {
    $accId = TestDataFactory::createAccount();
    $custId = TestDataFactory::createCustomer();
    $supId = TestDataFactory::createSupplier();

    // Positive control: valid receipt payment with customer only succeeds
    $validReceiptId = DB::table('payments')->insertGetId([
        'number' => 'PAY/VAL_REC/'.bin2hex(random_bytes(6)),
        'payment_type' => 'receipt',
        'customer_id' => $custId,
        'supplier_id' => null,
        'payment_date' => now()->toDateString(),
        'method' => 'cash',
        'account_id' => $accId,
        'amount' => 50000.00,
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($validReceiptId)->toBeGreaterThan(0);

    // Negative control 1: Without party fails with chk_payments_party
    ConstraintTestHelper::assertConstraintFailure(function () use ($accId) {
        DB::table('payments')->insert([
            'number' => 'PAY/NOPARTY/'.bin2hex(random_bytes(6)),
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
    }, 'chk_payments_party');

    // Negative control 2: With both parties fails with chk_payments_party
    ConstraintTestHelper::assertConstraintFailure(function () use ($accId, $custId, $supId) {
        DB::table('payments')->insert([
            'number' => 'PAY/BOTHPARTIES/'.bin2hex(random_bytes(6)),
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
    }, 'chk_payments_party');
});

test('payment allocations without target or with both sales and purchase invoices are rejected', function () {
    $payId = DB::table('payments')->insertGetId([
        'number' => 'PAY/ALLOC_TEST/'.bin2hex(random_bytes(6)),
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

    $siId = DB::table('sales_invoices')->insertGetId([
        'number' => 'SI/ALLOC/'.bin2hex(random_bytes(6)),
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
        'number' => 'PI/ALLOC/'.bin2hex(random_bytes(6)),
        'supplier_id' => $supId,
        'supplier_invoice_number' => 'SINV/'.bin2hex(random_bytes(6)),
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => 'draft',
        'subtotal' => 50000.00,
        'total' => 50000.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Positive control: valid allocation pointing strictly to sales_invoice_id succeeds
    $validAllocId = DB::table('payment_allocations')->insertGetId([
        'payment_id' => $payId,
        'sales_invoice_id' => $siId,
        'purchase_invoice_id' => null,
        'amount' => 10000.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($validAllocId)->toBeGreaterThan(0);

    // Negative control 1: Without target fails with chk_payment_allocations_target
    ConstraintTestHelper::assertConstraintFailure(function () use ($payId) {
        DB::table('payment_allocations')->insert([
            'payment_id' => $payId,
            'sales_invoice_id' => null,
            'purchase_invoice_id' => null,
            'amount' => 10000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }, 'chk_payment_allocations_target');

    // Negative control 2: With both targets fails with chk_payment_allocations_target
    ConstraintTestHelper::assertConstraintFailure(function () use ($payId, $siId, $piId) {
        DB::table('payment_allocations')->insert([
            'payment_id' => $payId,
            'sales_invoice_id' => $siId,
            'purchase_invoice_id' => $piId,
            'amount' => 10000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }, 'chk_payment_allocations_target');
});

test('duplicate document numbers are rejected by unique constraint', function () {
    $whId = TestDataFactory::createWarehouse();
    $docNumber = 'SO/DUP/'.bin2hex(random_bytes(6));

    // Positive control: First insert succeeds
    $firstId = TestDataFactory::createSalesOrder(['number' => $docNumber, 'warehouse_id' => $whId]);
    expect($firstId)->toBeGreaterThan(0);

    // Negative control: Duplicate document number fails with unique key
    ConstraintTestHelper::assertUniqueFailure(function () use ($whId, $docNumber) {
        TestDataFactory::createSalesOrder(['number' => $docNumber, 'warehouse_id' => $whId]);
    }, 'sales_orders_number_unique');
});

test('duplicate supplier invoice number per supplier is rejected by unique constraint', function () {
    $supId = TestDataFactory::createSupplier();
    $supInvNumber = 'INV-EXT-'.bin2hex(random_bytes(6));

    // Positive control: First invoice with supplier invoice number succeeds
    $firstInsert = DB::table('purchase_invoices')->insert([
        'number' => 'PI/1/'.bin2hex(random_bytes(6)),
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
    expect($firstInsert)->toBeTrue();

    // Negative control: Duplicate supplier_id + supplier_invoice_number fails
    ConstraintTestHelper::assertUniqueFailure(function () use ($supId, $supInvNumber) {
        DB::table('purchase_invoices')->insert([
            'number' => 'PI/2/'.bin2hex(random_bytes(6)),
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
    }, 'purchase_invoices_supplier_id_supplier_invoice_number_unique');
});

test('deleting referenced master record is strictly blocked by RESTRICT foreign key', function () {
    // Positive control: Deleting an unreferenced master record succeeds
    $unrefCatId = TestDataFactory::createCategory();
    expect(DB::table('product_categories')->where('id', $unrefCatId)->delete())->toBe(1);

    // Negative control: Deleting a category referenced by a product fails with RESTRICT foreign key
    $catId = TestDataFactory::createCategory();
    $prodId = TestDataFactory::createProduct(['category_id' => $catId]);
    expect($prodId)->toBeGreaterThan(0);

    ConstraintTestHelper::assertForeignKeyFailure(function () use ($catId) {
        DB::table('product_categories')->where('id', $catId)->delete();
    }, 'products_category_id_foreign');

    // Confirm category still exists in database
    expect(DB::table('product_categories')->where('id', $catId)->exists())->toBeTrue();
});

test('sales_invoices paid_amount exceeding total is rejected by check constraint', function () {
    $custId = TestDataFactory::createCustomer();

    // Positive control: paid_amount <= total succeeds
    $validInsert = DB::table('sales_invoices')->insert([
        'number' => 'SI/VALID_PAID/'.bin2hex(random_bytes(6)),
        'customer_id' => $custId,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => 'draft',
        'subtotal' => 10000.00,
        'total' => 10000.00,
        'paid_amount' => 5000.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($validInsert)->toBeTrue();

    // Negative control: paid_amount > total fails with chk_sales_invoices_paid
    ConstraintTestHelper::assertConstraintFailure(function () use ($custId) {
        DB::table('sales_invoices')->insert([
            'number' => 'SI/EXCEED/'.bin2hex(random_bytes(6)),
            'customer_id' => $custId,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 10000.00,
            'total' => 10000.00,
            'paid_amount' => 12000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }, 'chk_sales_invoices_paid');
});

test('append-only triggers strictly reject UPDATE and DELETE on audit_logs and stock_movements', function () {
    // 1. Audit logs positive control: insert succeeds
    $auditId = DB::table('audit_logs')->insertGetId([
        'event' => 'created',
        'auditable_type' => 'App\\Models\\Test',
        'auditable_id' => 1,
        'old_values' => json_encode(['foo' => 'bar']),
        'created_at' => now(),
    ]);
    expect($auditId)->toBeGreaterThan(0);

    // Audit logs negative control: UPDATE fails with trigger message
    ConstraintTestHelper::assertTriggerFailure(function () use ($auditId) {
        DB::table('audit_logs')->where('id', $auditId)->update(['event' => 'updated']);
    }, 'audit_logs is append-only');

    // Audit logs negative control: DELETE fails with trigger message
    ConstraintTestHelper::assertTriggerFailure(function () use ($auditId) {
        DB::table('audit_logs')->where('id', $auditId)->delete();
    }, 'audit_logs is append-only');

    // 2. Stock movements positive control: insert succeeds
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

    // Stock movements negative control: UPDATE fails with trigger message
    ConstraintTestHelper::assertTriggerFailure(function () use ($movementId) {
        DB::table('stock_movements')->where('id', $movementId)->update(['quantity' => 20.0000]);
    }, 'stock_movements is append-only');

    // Stock movements negative control: DELETE fails with trigger message
    ConstraintTestHelper::assertTriggerFailure(function () use ($movementId) {
        DB::table('stock_movements')->where('id', $movementId)->delete();
    }, 'stock_movements is append-only');
});

test('erp_app connection is strictly denied TRIGGER and ALTER privileges', function () {
    // Positive control: erp_app can execute standard DML (SELECT)
    $canSelect = DB::connection('mariadb')->table('units')->count();
    expect($canSelect)->toBeGreaterThanOrEqual(0);

    // Negative control: Attempt CREATE TRIGGER fails with privilege error
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

    // Negative control: Attempt ALTER TABLE fails with privilege error
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
