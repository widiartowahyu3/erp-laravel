<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('all 37 domain tables and 7 framework tables exist in database', function () {
    $expectedDomainTables = [
        // A. Administrasi
        'users',
        'roles',
        'permissions',
        'role_user',
        'permission_role',
        'audit_logs',
        'document_sequences',
        'settings',
        // B. Master Data
        'units',
        'product_categories',
        'products',
        'warehouses',
        'suppliers',
        'customers',
        'tax_rates',
        'payment_terms',
        // C. Pembelian
        'purchase_orders',
        'purchase_order_items',
        'goods_receipts',
        'goods_receipt_items',
        // D. Inventori
        'stock_balances',
        'stock_movements',
        'stock_adjustments',
        'stock_adjustment_items',
        // E. Penjualan
        'sales_orders',
        'sales_order_items',
        'delivery_orders',
        'delivery_order_items',
        // F. Keuangan
        'chart_of_accounts',
        'journals',
        'journal_lines',
        'sales_invoices',
        'sales_invoice_items',
        'purchase_invoices',
        'purchase_invoice_items',
        'payments',
        'payment_allocations',
    ];

    $expectedFrameworkTables = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
    ];

    expect($expectedDomainTables)->toHaveCount(37);
    expect($expectedFrameworkTables)->toHaveCount(7);

    foreach ($expectedDomainTables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Domain table [{$table}] must exist in database");
    }

    foreach ($expectedFrameworkTables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Framework table [{$table}] must exist in database");
    }

    // Prohibited legacy table must NOT exist
    expect(Schema::hasTable('password_reset_tokens'))->toBeFalse('Table password_reset_tokens must not exist');
});

test('every domain and framework table has a primary key', function () {
    $database = DB::connection()->getDatabaseName();

    $tablesWithoutPk = DB::select("
        SELECT t.TABLE_NAME
        FROM information_schema.TABLES t
        LEFT JOIN information_schema.KEY_COLUMN_USAGE k
            ON t.TABLE_SCHEMA = k.TABLE_SCHEMA
            AND t.TABLE_NAME = k.TABLE_NAME
            AND k.CONSTRAINT_NAME = 'PRIMARY'
        WHERE t.TABLE_SCHEMA = ?
          AND t.TABLE_TYPE = 'BASE TABLE'
          AND k.COLUMN_NAME IS NULL
    ", [$database]);

    $tableNames = array_map(fn ($row) => $row->TABLE_NAME, $tablesWithoutPk);

    expect($tableNames)->toBeEmpty('All tables must have a primary key. Missing PK: '.implode(', ', $tableNames));
});

test('foreign key rules in information_schema strictly enforce RESTRICT except role_user and permission_role CASCADE', function () {
    $database = DB::connection()->getDatabaseName();

    $constraints = DB::select('
        SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE, UPDATE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = ?
    ', [$database]);

    expect($constraints)->not->toBeEmpty('Referential constraints must be present in schema');

    $cascadeTables = ['role_user', 'permission_role'];

    foreach ($constraints as $rc) {
        if (in_array($rc->TABLE_NAME, $cascadeTables, true)) {
            expect($rc->DELETE_RULE)->toBe('CASCADE', "Constraint [{$rc->CONSTRAINT_NAME}] on [{$rc->TABLE_NAME}] must be CASCADE");
        } else {
            expect($rc->DELETE_RULE)->toBe('RESTRICT', "Constraint [{$rc->CONSTRAINT_NAME}] on [{$rc->TABLE_NAME}] must be RESTRICT");
        }
        expect($rc->UPDATE_RULE)->toBe('RESTRICT', "Constraint [{$rc->CONSTRAINT_NAME}] on [{$rc->TABLE_NAME}] must be ON UPDATE RESTRICT");
    }
});

test('numeric columns strictly comply with decimal precision standards without float or double', function () {
    $database = DB::connection()->getDatabaseName();

    // 1. Ensure zero float or double in entire domain schema
    $floatColumns = DB::select("
        SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND DATA_TYPE IN ('float', 'double')
    ", [$database]);

    expect($floatColumns)->toBeEmpty('Float or double columns are strictly forbidden in schema');

    // 2. Verify money columns: decimal(18,2)
    $moneyColumns = DB::select("
        SELECT TABLE_NAME, COLUMN_NAME, NUMERIC_PRECISION, NUMERIC_SCALE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND (
            COLUMN_NAME IN ('subtotal', 'total', 'debit', 'credit', 'amount')
            OR COLUMN_NAME LIKE '%_amount'
          )
    ", [$database]);

    expect($moneyColumns)->not->toBeEmpty();
    foreach ($moneyColumns as $col) {
        expect((int) $col->NUMERIC_PRECISION)->toBe(18, "Column [{$col->TABLE_NAME}.{$col->COLUMN_NAME}] precision must be 18");
        expect((int) $col->NUMERIC_SCALE)->toBe(2, "Column [{$col->TABLE_NAME}.{$col->COLUMN_NAME}] scale must be 2");
    }

    // 3. Verify quantity columns: decimal(18,4)
    $quantityColumns = DB::select("
        SELECT TABLE_NAME, COLUMN_NAME, NUMERIC_PRECISION, NUMERIC_SCALE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND (
            COLUMN_NAME = 'quantity'
            OR COLUMN_NAME LIKE '%_quantity'
          )
    ", [$database]);

    expect($quantityColumns)->not->toBeEmpty();
    foreach ($quantityColumns as $col) {
        expect((int) $col->NUMERIC_PRECISION)->toBe(18, "Column [{$col->TABLE_NAME}.{$col->COLUMN_NAME}] precision must be 18");
        expect((int) $col->NUMERIC_SCALE)->toBe(4, "Column [{$col->TABLE_NAME}.{$col->COLUMN_NAME}] scale must be 4");
    }

    // 4. Verify unit price & cost columns: decimal(18,4)
    $costColumns = DB::select("
        SELECT TABLE_NAME, COLUMN_NAME, NUMERIC_PRECISION, NUMERIC_SCALE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND (
            COLUMN_NAME IN ('unit_price', 'unit_cost', 'avg_cost', 'balance_after', 'avg_cost_after')
            OR COLUMN_NAME LIKE '%_avg_cost'
          )
    ", [$database]);

    expect($costColumns)->not->toBeEmpty();
    foreach ($costColumns as $col) {
        expect((int) $col->NUMERIC_PRECISION)->toBe(18, "Column [{$col->TABLE_NAME}.{$col->COLUMN_NAME}] precision must be 18");
        expect((int) $col->NUMERIC_SCALE)->toBe(4, "Column [{$col->TABLE_NAME}.{$col->COLUMN_NAME}] scale must be 4");
    }

    // 5. Verify tax_rates.rate is decimal(5,2)
    $taxRateCol = DB::selectOne("
        SELECT NUMERIC_PRECISION, NUMERIC_SCALE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tax_rates' AND COLUMN_NAME = 'rate'
    ", [$database]);

    expect((int) $taxRateCol->NUMERIC_PRECISION)->toBe(5);
    expect((int) $taxRateCol->NUMERIC_SCALE)->toBe(2);
});

test('seeders are idempotent and COA hierarchy is structurally balanced', function () {
    // Run DatabaseSeeder twice
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
    $unitsCount1 = DB::table('units')->count();
    $paymentTermsCount1 = DB::table('payment_terms')->count();
    $taxRatesCount1 = DB::table('tax_rates')->count();
    $coaCount1 = DB::table('chart_of_accounts')->count();

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
    $unitsCount2 = DB::table('units')->count();
    $paymentTermsCount2 = DB::table('payment_terms')->count();
    $taxRatesCount2 = DB::table('tax_rates')->count();
    $coaCount2 = DB::table('chart_of_accounts')->count();

    expect($unitsCount1)->toBe(5);
    expect($unitsCount2)->toBe(5);
    expect($paymentTermsCount1)->toBe(4);
    expect($paymentTermsCount2)->toBe(4);
    expect($taxRatesCount1)->toBe(1);
    expect($taxRatesCount2)->toBe(1);
    expect($coaCount1)->toBe(20);
    expect($coaCount2)->toBe(20);

    // Verify all non-root accounts have an existing parent
    $orphanedAccounts = DB::table('chart_of_accounts as child')
        ->leftJoin('chart_of_accounts as parent', 'child.parent_id', '=', 'parent.id')
        ->whereNotNull('child.parent_id')
        ->whereNull('parent.id')
        ->count();

    expect($orphanedAccounts)->toBe(0, 'All child accounts must have valid parent accounts');

    // Verify root headers have null parent_id and is_postable = false
    $rootHeaders = DB::table('chart_of_accounts')->whereNull('parent_id')->get();
    expect($rootHeaders)->toHaveCount(6);
    foreach ($rootHeaders as $header) {
        expect((bool) $header->is_postable)->toBeFalse("Header account [{$header->code}] must not be postable");
    }
});

test('index parity is strictly enforced between sales_invoices and purchase_invoices', function () {
    $database = DB::connection()->getDatabaseName();

    $indexes = DB::select('
        SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME IN (\'sales_invoices\', \'purchase_invoices\')
        GROUP BY TABLE_NAME, INDEX_NAME
    ', [$database]);

    $grouped = [];
    foreach ($indexes as $idx) {
        $grouped[$idx->TABLE_NAME][$idx->INDEX_NAME] = $idx->columns;
    }

    // 1. Verify sales_invoices has party+status and due_date indexes
    expect($grouped['sales_invoices'])->toHaveKey('sales_invoices_customer_id_status_index');
    expect($grouped['sales_invoices']['sales_invoices_customer_id_status_index'])->toBe('customer_id,status');

    expect($grouped['sales_invoices'])->toHaveKey('sales_invoices_due_date_index');
    expect($grouped['sales_invoices']['sales_invoices_due_date_index'])->toBe('due_date');

    // 2. Verify purchase_invoices has party+status and due_date indexes
    expect($grouped['purchase_invoices'])->toHaveKey('purchase_invoices_supplier_id_status_index');
    expect($grouped['purchase_invoices']['purchase_invoices_supplier_id_status_index'])->toBe('supplier_id,status');

    expect($grouped['purchase_invoices'])->toHaveKey('purchase_invoices_due_date_index');
    expect($grouped['purchase_invoices']['purchase_invoices_due_date_index'])->toBe('due_date');
});
