<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\DeliveryOrderStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\JournalStatus;
use App\Enums\NormalBalance;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockMovementType;
use Illuminate\Support\Facades\DB;

test('PHP enum values match MariaDB CHECK constraint definitions with zero drift', function (
    string $constraintName,
    string $enumClass
) {
    $row = DB::table('information_schema.CHECK_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', config('database.connections.mariadb.database'))
        ->where('CONSTRAINT_NAME', $constraintName)
        ->first();

    expect($row)->not->toBeNull("Constraint '{$constraintName}' not found in database.");

    $clause = (string) $row->CHECK_CLAUSE;
    preg_match_all("/'([^']+)'/", $clause, $matches);
    $dbValues = $matches[1] ?? [];
    sort($dbValues);

    /** @var array<string, string> $cases */
    $cases = $enumClass::cases();
    $enumValues = array_map(fn ($case) => (string) $case->value, $cases);
    sort($enumValues);

    expect($dbValues)->not->toBeEmpty()
        ->and($enumValues)->not->toBeEmpty()
        ->and($dbValues)->toBe($enumValues)
        ->and(array_diff($dbValues, $enumValues))->toBeEmpty("Database constraint '{$constraintName}' has values not in enum {$enumClass}")
        ->and(array_diff($enumValues, $dbValues))->toBeEmpty("Enum {$enumClass} has values not in database constraint '{$constraintName}'");
})->with([
    'PurchaseOrderStatus' => ['chk_purchase_orders_status', PurchaseOrderStatus::class],
    'SalesOrderStatus' => ['chk_sales_orders_status', SalesOrderStatus::class],
    'GoodsReceiptStatus' => ['chk_goods_receipts_status', GoodsReceiptStatus::class],
    'StockAdjustmentStatus' => ['chk_stock_adjustments_status', StockAdjustmentStatus::class],
    'DeliveryOrderStatus' => ['chk_delivery_orders_status', DeliveryOrderStatus::class],
    'PaymentStatus' => ['chk_payments_status', PaymentStatus::class],
    'JournalStatus' => ['chk_journals_status', JournalStatus::class],
    'SalesInvoiceStatus' => ['chk_sales_invoices_status', SalesInvoiceStatus::class],
    'PurchaseInvoiceStatus' => ['chk_purchase_invoices_status', PurchaseInvoiceStatus::class],
    'PaymentType' => ['chk_payments_type', PaymentType::class],
    'PaymentMethod' => ['chk_payments_method', PaymentMethod::class],
    'StockMovementType' => ['chk_stock_movements_type', StockMovementType::class],
    'AccountType' => ['chk_coa_type', AccountType::class],
    'NormalBalance' => ['chk_coa_normal_balance', NormalBalance::class],
]);
