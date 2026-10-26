<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

class TestDataFactory
{
    public static function createUser(array $overrides = []): int
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'name' => 'Test User '.bin2hex(random_bytes(4)),
            'email' => 'user_'.bin2hex(random_bytes(6)).'@example.com',
            'password' => 'secret_hash',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (int) $id;
    }

    public static function createUnit(array $overrides = []): int
    {
        $id = DB::table('units')->insertGetId(array_merge([
            'code' => 'U'.mt_rand(10000000, 99999999),
            'name' => 'Unit Test',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (int) $id;
    }

    public static function createCategory(array $overrides = []): int
    {
        $id = DB::table('product_categories')->insertGetId(array_merge([
            'code' => 'CAT_'.bin2hex(random_bytes(8)),
            'name' => 'Category Test',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (int) $id;
    }

    public static function createWarehouse(array $overrides = []): int
    {
        $id = DB::table('warehouses')->insertGetId(array_merge([
            'code' => 'WH_'.bin2hex(random_bytes(6)),
            'name' => 'Warehouse Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (int) $id;
    }

    public static function createSupplier(array $overrides = []): int
    {
        $id = DB::table('suppliers')->insertGetId(array_merge([
            'code' => 'SUP_'.bin2hex(random_bytes(8)),
            'name' => 'Supplier Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (int) $id;
    }

    public static function createCustomer(array $overrides = []): int
    {
        $id = DB::table('customers')->insertGetId(array_merge([
            'code' => 'CST_'.bin2hex(random_bytes(8)),
            'name' => 'Customer Test',
            'credit_limit' => 10000000.00,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (int) $id;
    }

    public static function createProduct(array $overrides = []): int
    {
        $catId = $overrides['category_id'] ?? self::createCategory();
        $unitId = $overrides['base_unit_id'] ?? self::createUnit();

        $id = DB::table('products')->insertGetId(array_merge([
            'sku' => 'SKU_'.bin2hex(random_bytes(10)),
            'name' => 'Product Test',
            'category_id' => $catId,
            'base_unit_id' => $unitId,
            'track_stock' => true,
            'selling_price' => 50000.00,
            'min_stock' => 10.0000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (int) $id;
    }

    public static function createAccount(array $overrides = []): int
    {
        $id = DB::table('chart_of_accounts')->insertGetId(array_merge([
            'code' => 'A'.bin2hex(random_bytes(6)),
            'name' => 'Account Test',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (int) $id;
    }

    public static function createSalesOrder(array $overrides = []): int
    {
        $custId = $overrides['customer_id'] ?? self::createCustomer();
        $whId = $overrides['warehouse_id'] ?? self::createWarehouse();

        $id = DB::table('sales_orders')->insertGetId(array_merge([
            'number' => 'SO/'.bin2hex(random_bytes(8)),
            'customer_id' => $custId,
            'warehouse_id' => $whId,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 100000.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'total' => 100000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (int) $id;
    }

    public static function createPurchaseOrder(array $overrides = []): int
    {
        $supId = $overrides['supplier_id'] ?? self::createSupplier();
        $whId = $overrides['warehouse_id'] ?? self::createWarehouse();

        $id = DB::table('purchase_orders')->insertGetId(array_merge([
            'number' => 'PO/'.bin2hex(random_bytes(8)),
            'supplier_id' => $supId,
            'warehouse_id' => $whId,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 100000.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'total' => 100000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (int) $id;
    }
}
