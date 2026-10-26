<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Get the role permission mappings.
     *
     * @return array<string, array{display_name: string, description: string, permissions: array<int, string>}>
     */
    public static function roleDefinitions(): array
    {
        $allPermissions = array_column(PermissionSeeder::permissions(), 'name');

        // Admin permissions:
        // users.*, roles.*, settings.*, audit_logs.view, all master data (*), all *.view, all reports.view_*; NO approve/post/void
        $adminPermissions = [
            'users.view', 'users.create', 'users.update', 'users.delete',
            'roles.view', 'roles.create', 'roles.update', 'roles.delete',
            'settings.view', 'settings.update',
            'audit_logs.view',
            // Master data (8 resources x 4)
            'units.view', 'units.create', 'units.update', 'units.delete',
            'product_categories.view', 'product_categories.create', 'product_categories.update', 'product_categories.delete',
            'products.view', 'products.create', 'products.update', 'products.delete',
            'warehouses.view', 'warehouses.create', 'warehouses.update', 'warehouses.delete',
            'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete',
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',
            'tax_rates.view', 'tax_rates.create', 'tax_rates.update', 'tax_rates.delete',
            'payment_terms.view', 'payment_terms.create', 'payment_terms.update', 'payment_terms.delete',
            // All other views
            'chart_of_accounts.view',
            'purchase_orders.view',
            'sales_orders.view',
            'goods_receipts.view',
            'stock_adjustments.view',
            'delivery_orders.view',
            'sales_invoices.view',
            'purchase_invoices.view',
            'payments.view',
            'journals.view',
            'stock_balances.view',
            'stock_movements.view',
            // All reports
            'reports.view_sales',
            'reports.view_purchasing',
            'reports.view_inventory',
            'reports.view_finance',
        ];

        // Purchasing:
        // view on units, product_categories, products, warehouses, tax_rates, payment_terms;
        // suppliers.view/create/update;
        // purchase_orders.view/create/update/approve/void;
        // goods_receipts.view; purchase_invoices.view; stock_balances.view; reports.view_purchasing.
        $purchasingPermissions = [
            'units.view',
            'product_categories.view',
            'products.view',
            'warehouses.view',
            'tax_rates.view',
            'payment_terms.view',
            'suppliers.view',
            'suppliers.create',
            'suppliers.update',
            'purchase_orders.view',
            'purchase_orders.create',
            'purchase_orders.update',
            'purchase_orders.approve',
            'purchase_orders.void',
            'goods_receipts.view',
            'purchase_invoices.view',
            'stock_balances.view',
            'reports.view_purchasing',
        ];

        // Warehouse:
        // view on units, product_categories, products, warehouses;
        // goods_receipts.* (view/create/update/post/void);
        // stock_adjustments.*;
        // delivery_orders.*;
        // purchase_orders.view; sales_orders.view; stock_balances.view; stock_movements.view; reports.view_inventory.
        $warehousePermissions = [
            'units.view',
            'product_categories.view',
            'products.view',
            'warehouses.view',
            'goods_receipts.view',
            'goods_receipts.create',
            'goods_receipts.update',
            'goods_receipts.post',
            'goods_receipts.void',
            'stock_adjustments.view',
            'stock_adjustments.create',
            'stock_adjustments.update',
            'stock_adjustments.post',
            'stock_adjustments.void',
            'delivery_orders.view',
            'delivery_orders.create',
            'delivery_orders.update',
            'delivery_orders.post',
            'delivery_orders.void',
            'purchase_orders.view',
            'sales_orders.view',
            'stock_balances.view',
            'stock_movements.view',
            'reports.view_inventory',
        ];

        // Sales:
        // view on units, product_categories, products, tax_rates, payment_terms;
        // customers.view/create/update;
        // sales_orders.view/create/update/approve/void;
        // delivery_orders.view; sales_invoices.view; stock_balances.view; reports.view_sales.
        $salesPermissions = [
            'units.view',
            'product_categories.view',
            'products.view',
            'tax_rates.view',
            'payment_terms.view',
            'customers.view',
            'customers.create',
            'customers.update',
            'sales_orders.view',
            'sales_orders.create',
            'sales_orders.update',
            'sales_orders.approve',
            'sales_orders.void',
            'delivery_orders.view',
            'sales_invoices.view',
            'stock_balances.view',
            'reports.view_sales',
        ];

        // Finance:
        // chart_of_accounts.*, journals.*, sales_invoices.*, purchase_invoices.*, payments.*;
        // view on customers, suppliers, tax_rates, payment_terms, purchase_orders, sales_orders, goods_receipts, delivery_orders;
        // reports.view_finance, reports.view_sales, reports.view_purchasing.
        $financePermissions = [
            'chart_of_accounts.view',
            'chart_of_accounts.create',
            'chart_of_accounts.update',
            'chart_of_accounts.delete',
            'journals.view',
            'journals.create',
            'journals.update',
            'journals.post',
            'journals.void',
            'sales_invoices.view',
            'sales_invoices.create',
            'sales_invoices.update',
            'sales_invoices.post',
            'sales_invoices.void',
            'purchase_invoices.view',
            'purchase_invoices.create',
            'purchase_invoices.update',
            'purchase_invoices.post',
            'purchase_invoices.void',
            'payments.view',
            'payments.create',
            'payments.update',
            'payments.post',
            'payments.void',
            'customers.view',
            'suppliers.view',
            'tax_rates.view',
            'payment_terms.view',
            'purchase_orders.view',
            'sales_orders.view',
            'goods_receipts.view',
            'delivery_orders.view',
            'reports.view_finance',
            'reports.view_sales',
            'reports.view_purchasing',
        ];

        // Viewer:
        // all *.view EXCEPT users, roles, settings, audit_logs; all reports.view_*
        $viewerExcludedViews = [
            'users.view',
            'roles.view',
            'settings.view',
            'audit_logs.view',
        ];
        $viewPermissions = array_filter($allPermissions, function (string $p) use ($viewerExcludedViews): bool {
            return str_ends_with($p, '.view') && ! in_array($p, $viewerExcludedViews, true);
        });
        $reportPermissions = array_filter($allPermissions, function (string $p): bool {
            return str_starts_with($p, 'reports.view_');
        });
        $viewerPermissions = array_values(array_unique(array_merge($viewPermissions, $reportPermissions)));

        return [
            'super_admin' => [
                'display_name' => 'Super Administrator',
                'description' => 'Akses penuh ke seluruh modul dan operasional sistem ERP',
                'permissions' => $allPermissions,
            ],
            'admin' => [
                'display_name' => 'Administrator',
                'description' => 'Pengelolaan data master, pengguna, konfigurasi, dan monitoring audit',
                'permissions' => $adminPermissions,
            ],
            'purchasing' => [
                'display_name' => 'Bagian Pembelian (Purchasing)',
                'description' => 'Operasional pengadaan, pesanan pembelian, dan data pemasok',
                'permissions' => $purchasingPermissions,
            ],
            'warehouse' => [
                'display_name' => 'Bagian Gudang (Warehouse)',
                'description' => 'Operasional penerimaan barang, pengiriman, dan penyesuaian inventori',
                'permissions' => $warehousePermissions,
            ],
            'sales' => [
                'display_name' => 'Bagian Penjualan (Sales)',
                'description' => 'Operasional pesanan penjualan, data pelanggan, dan pemantauan faktur',
                'permissions' => $salesPermissions,
            ],
            'finance' => [
                'display_name' => 'Bagian Keuangan (Finance)',
                'description' => 'Operasional faktur pembelian/penjualan, pembayaran, jurnal, dan COA',
                'permissions' => $financePermissions,
            ],
            'viewer' => [
                'display_name' => 'Pengamat (Viewer)',
                'description' => 'Akses baca dokumen operasional, inventori, dan laporan bisnis',
                'permissions' => $viewerPermissions,
            ],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();
        $definitions = self::roleDefinitions();

        // 1. Fetch all permissions map: name => id
        $permissionMap = DB::table('permissions')->pluck('id', 'name')->all();

        foreach ($definitions as $roleName => $roleData) {
            // Upsert role
            DB::table('roles')->upsert([
                [
                    'name' => $roleName,
                    'display_name' => $roleData['display_name'],
                    'description' => $roleData['description'],
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ], ['name'], ['display_name', 'description', 'is_system', 'updated_at']);

            $roleId = (int) DB::table('roles')->where('name', $roleName)->value('id');

            // Collect target permission IDs
            $targetPermissionIds = [];
            foreach ($roleData['permissions'] as $permName) {
                if (isset($permissionMap[$permName])) {
                    $targetPermissionIds[] = (int) $permissionMap[$permName];
                }
            }

            // Sync pivot permission_role: delete outdated pivots for this system role
            DB::table('permission_role')
                ->where('role_id', $roleId)
                ->whereNotIn('permission_id', $targetPermissionIds)
                ->delete();

            // Insert new pivot rows that are not yet present
            $existingPermissionIds = DB::table('permission_role')
                ->where('role_id', $roleId)
                ->pluck('permission_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $newPermissionIds = array_diff($targetPermissionIds, $existingPermissionIds);

            if (! empty($newPermissionIds)) {
                $pivotRows = array_map(function (int $permId) use ($roleId): array {
                    return [
                        'role_id' => $roleId,
                        'permission_id' => $permId,
                    ];
                }, $newPermissionIds);

                DB::table('permission_role')->insert($pivotRows);
            }
        }
    }
}
