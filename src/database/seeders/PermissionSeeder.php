<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    /**
     * Get the full catalog of permissions.
     *
     * @return array<int, array{name: string, module: string, display_name: string}>
     */
    public static function permissions(): array
    {
        $permissions = [];

        // 1. CRUD (view, create, update, delete) for 11 resources (11 x 4 = 44)
        $crudResources = [
            'administration' => [
                'users' => 'Pengguna',
                'roles' => 'Peran Pengguna',
            ],
            'master_data' => [
                'units' => 'Satuan Ukuran',
                'product_categories' => 'Kategori Produk',
                'products' => 'Master Produk',
                'warehouses' => 'Master Gudang',
                'suppliers' => 'Master Pemasok',
                'customers' => 'Master Pelanggan',
                'tax_rates' => 'Tarif Pajak',
                'payment_terms' => 'Syarat Pembayaran',
            ],
            'finance' => [
                'chart_of_accounts' => 'Bagan Akun (COA)',
            ],
        ];

        $actions = [
            'view' => 'Melihat',
            'create' => 'Menambah',
            'update' => 'Mengubah',
            'delete' => 'Menghapus',
        ];

        foreach ($crudResources as $module => $resources) {
            foreach ($resources as $resource => $label) {
                foreach ($actions as $action => $actionLabel) {
                    $permissions[] = [
                        'name' => "{$resource}.{$action}",
                        'module' => $module,
                        'display_name' => "{$actionLabel} {$label}",
                    ];
                }
            }
        }

        // 2. Single administration permissions (3)
        $adminSingles = [
            ['name' => 'settings.view', 'module' => 'administration', 'display_name' => 'Melihat Pengaturan Sistem'],
            ['name' => 'settings.update', 'module' => 'administration', 'display_name' => 'Mengubah Pengaturan Sistem'],
            ['name' => 'audit_logs.view', 'module' => 'administration', 'display_name' => 'Melihat Log Audit'],
        ];
        foreach ($adminSingles as $perm) {
            $permissions[] = $perm;
        }

        // 3. Document 5 actions (9 x 5 = 45)
        // purchase_orders, sales_orders: view, create, update, approve, void
        // other 7 documents: view, create, update, post, void
        $documents = [
            ['resource' => 'purchase_orders', 'module' => 'purchasing', 'label' => 'Pesanan Pembelian (PO)', 'is_order' => true],
            ['resource' => 'sales_orders', 'module' => 'sales', 'label' => 'Pesanan Penjualan (SO)', 'is_order' => true],
            ['resource' => 'goods_receipts', 'module' => 'purchasing', 'label' => 'Penerimaan Barang (GR)', 'is_order' => false],
            ['resource' => 'stock_adjustments', 'module' => 'inventory', 'label' => 'Penyesuaian Stok', 'is_order' => false],
            ['resource' => 'delivery_orders', 'module' => 'sales', 'label' => 'Surat Jalan / Pengiriman (DO)', 'is_order' => false],
            ['resource' => 'sales_invoices', 'module' => 'sales', 'label' => 'Faktur Penjualan (SI)', 'is_order' => false],
            ['resource' => 'purchase_invoices', 'module' => 'purchasing', 'label' => 'Faktur Pembelian (PI)', 'is_order' => false],
            ['resource' => 'payments', 'module' => 'finance', 'label' => 'Pembayaran Kas/Bank', 'is_order' => false],
            ['resource' => 'journals', 'module' => 'finance', 'label' => 'Jurnal Akuntansi', 'is_order' => false],
        ];

        foreach ($documents as $doc) {
            $resource = $doc['resource'];
            $module = $doc['module'];
            $label = $doc['label'];
            $fourthAction = $doc['is_order'] ? 'approve' : 'post';
            $fourthLabel = $doc['is_order'] ? 'Menyetujui' : 'Memposting';

            $permissions[] = ['name' => "{$resource}.view", 'module' => $module, 'display_name' => "Melihat {$label}"];
            $permissions[] = ['name' => "{$resource}.create", 'module' => $module, 'display_name' => "Membuat {$label}"];
            $permissions[] = ['name' => "{$resource}.update", 'module' => $module, 'display_name' => "Mengubah {$label}"];
            $permissions[] = ['name' => "{$resource}.{$fourthAction}", 'module' => $module, 'display_name' => "{$fourthLabel} {$label}"];
            $permissions[] = ['name' => "{$resource}.void", 'module' => $module, 'display_name' => "Membatalkan (Void) {$label}"];
        }

        // 4. Inventory views (2)
        $permissions[] = ['name' => 'stock_balances.view', 'module' => 'inventory', 'display_name' => 'Melihat Saldo Stok'];
        $permissions[] = ['name' => 'stock_movements.view', 'module' => 'inventory', 'display_name' => 'Melihat Mutasi Stok'];

        // 5. Reporting permissions (4)
        $permissions[] = ['name' => 'reports.view_sales', 'module' => 'reporting', 'display_name' => 'Melihat Laporan Penjualan'];
        $permissions[] = ['name' => 'reports.view_purchasing', 'module' => 'reporting', 'display_name' => 'Melihat Laporan Pembelian'];
        $permissions[] = ['name' => 'reports.view_inventory', 'module' => 'reporting', 'display_name' => 'Melihat Laporan Persediaan'];
        $permissions[] = ['name' => 'reports.view_finance', 'module' => 'reporting', 'display_name' => 'Melihat Laporan Keuangan'];

        return $permissions;
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();
        $permissions = self::permissions();

        $rows = array_map(function (array $perm) use ($now): array {
            return [
                'name' => $perm['name'],
                'module' => $perm['module'],
                'display_name' => $perm['display_name'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $permissions);

        DB::table('permissions')->upsert($rows, ['name'], ['module', 'display_name', 'updated_at']);
    }
}
