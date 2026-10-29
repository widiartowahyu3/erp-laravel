<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ReferenceGuard
{
    /**
     * Downstream relational mapping for foreign key integrity validation.
     *
     * @var array<string, list<array{table: string, column: string, label: string}>>
     */
    private const REFERENCE_MAP = [
        'units' => [
            ['table' => 'products', 'column' => 'base_unit_id', 'label' => 'Produk'],
        ],
        'product_categories' => [
            ['table' => 'product_categories', 'column' => 'parent_id', 'label' => 'Subkategori Produk'],
            ['table' => 'products', 'column' => 'category_id', 'label' => 'Produk'],
        ],
        'products' => [
            ['table' => 'purchase_order_items', 'column' => 'product_id', 'label' => 'Pesanan Pembelian'],
            ['table' => 'goods_receipt_items', 'column' => 'product_id', 'label' => 'Penerimaan Barang'],
            ['table' => 'sales_order_items', 'column' => 'product_id', 'label' => 'Pesanan Penjualan'],
            ['table' => 'delivery_order_items', 'column' => 'product_id', 'label' => 'Surat Jalan / Pengiriman'],
            ['table' => 'purchase_invoice_items', 'column' => 'product_id', 'label' => 'Faktur Pembelian'],
            ['table' => 'sales_invoice_items', 'column' => 'product_id', 'label' => 'Faktur Penjualan'],
            ['table' => 'stock_balances', 'column' => 'product_id', 'label' => 'Saldo Stok'],
            ['table' => 'stock_movements', 'column' => 'product_id', 'label' => 'Mutasi Stok'],
            ['table' => 'stock_adjustment_items', 'column' => 'product_id', 'label' => 'Penyesuaian Stok'],
        ],
        'warehouses' => [
            ['table' => 'purchase_orders', 'column' => 'warehouse_id', 'label' => 'Pesanan Pembelian'],
            ['table' => 'goods_receipts', 'column' => 'warehouse_id', 'label' => 'Penerimaan Barang'],
            ['table' => 'sales_orders', 'column' => 'warehouse_id', 'label' => 'Pesanan Penjualan'],
            ['table' => 'delivery_orders', 'column' => 'warehouse_id', 'label' => 'Surat Jalan / Pengiriman'],
            ['table' => 'stock_balances', 'column' => 'warehouse_id', 'label' => 'Saldo Stok'],
            ['table' => 'stock_movements', 'column' => 'warehouse_id', 'label' => 'Mutasi Stok'],
            ['table' => 'stock_adjustments', 'column' => 'warehouse_id', 'label' => 'Penyesuaian Stok'],
        ],
        'suppliers' => [
            ['table' => 'purchase_orders', 'column' => 'supplier_id', 'label' => 'Pesanan Pembelian'],
            ['table' => 'goods_receipts', 'column' => 'supplier_id', 'label' => 'Penerimaan Barang'],
            ['table' => 'purchase_invoices', 'column' => 'supplier_id', 'label' => 'Faktur Pembelian'],
            ['table' => 'payments', 'column' => 'supplier_id', 'label' => 'Pembayaran Kas/Bank'],
        ],
        'customers' => [
            ['table' => 'sales_orders', 'column' => 'customer_id', 'label' => 'Pesanan Penjualan'],
            ['table' => 'delivery_orders', 'column' => 'customer_id', 'label' => 'Surat Jalan / Pengiriman'],
            ['table' => 'sales_invoices', 'column' => 'customer_id', 'label' => 'Faktur Penjualan'],
            ['table' => 'payments', 'column' => 'customer_id', 'label' => 'Pembayaran Kas/Bank'],
        ],
        'tax_rates' => [
            ['table' => 'purchase_order_items', 'column' => 'tax_rate_id', 'label' => 'Pesanan Pembelian'],
            ['table' => 'sales_order_items', 'column' => 'tax_rate_id', 'label' => 'Pesanan Penjualan'],
            ['table' => 'purchase_invoice_items', 'column' => 'tax_rate_id', 'label' => 'Faktur Pembelian'],
            ['table' => 'sales_invoice_items', 'column' => 'tax_rate_id', 'label' => 'Faktur Penjualan'],
        ],
        'payment_terms' => [
            ['table' => 'suppliers', 'column' => 'payment_term_id', 'label' => 'Pemasok'],
            ['table' => 'customers', 'column' => 'payment_term_id', 'label' => 'Pelanggan'],
            ['table' => 'purchase_orders', 'column' => 'payment_term_id', 'label' => 'Pesanan Pembelian'],
            ['table' => 'sales_orders', 'column' => 'payment_term_id', 'label' => 'Pesanan Penjualan'],
        ],
    ];

    /**
     * Check if a master data record is referenced by any downstream domain table.
     *
     * @throws BusinessRuleException
     */
    public static function assertCanDelete(Model $model, string $moduleName): void
    {
        $table = $model->getTable();
        $refs = self::REFERENCE_MAP[$table] ?? [];

        foreach ($refs as $ref) {
            $query = DB::table($ref['table'])
                ->where($ref['column'], '=', $model->getKey());

            // If downstream table uses soft deletes, check non-deleted rows
            if (Schema::hasColumn($ref['table'], 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            if ($query->exists()) {
                throw new BusinessRuleException(
                    "Tidak dapat menghapus {$moduleName} karena masih digunakan oleh data {$ref['label']}.",
                    409
                );
            }
        }
    }
}
