<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\StockMovement;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class DashboardSummary
{
    /**
     * Ringkasan Pesanan Pembelian berjalan (status approved + partially_received).
     *
     * @return array{active_po_count: int, active_po_total: string}
     */
    public function getPurchaseOrdersSummary(): array
    {
        $data = DB::table('purchase_orders')
            ->whereIn('status', ['approved', 'partially_received'])
            ->selectRaw('COUNT(*) as total_count, COALESCE(SUM(total), 0) as total_amount')
            ->first();

        return [
            'active_po_count' => (int) ($data->total_count ?? 0),
            'active_po_total' => Decimal::round((string) ($data->total_amount ?? '0'), Decimal::SCALE_MONEY),
        ];
    }

    /**
     * Ringkasan Penerimaan Barang draf menunggu posting.
     *
     * @return array{draft_gr_count: int}
     */
    public function getGoodsReceiptsSummary(): array
    {
        $count = DB::table('goods_receipts')
            ->where('status', 'draft')
            ->count();

        return [
            'draft_gr_count' => $count,
        ];
    }

    /**
     * Ringkasan Pesanan Penjualan berjalan (status approved + partially_delivered).
     *
     * @return array{active_so_count: int, active_so_total: string}
     */
    public function getSalesOrdersSummary(): array
    {
        $data = DB::table('sales_orders')
            ->whereIn('status', ['approved', 'partially_delivered'])
            ->selectRaw('COUNT(*) as total_count, COALESCE(SUM(total), 0) as total_amount')
            ->first();

        return [
            'active_so_count' => (int) ($data->total_count ?? 0),
            'active_so_total' => Decimal::round((string) ($data->total_amount ?? '0'), Decimal::SCALE_MONEY),
        ];
    }

    /**
     * Ringkasan Surat Jalan draf menunggu pengiriman / posting.
     *
     * @return array{draft_do_count: int}
     */
    public function getDeliveryOrdersSummary(): array
    {
        $count = DB::table('delivery_orders')
            ->where('status', 'draft')
            ->count();

        return [
            'draft_do_count' => $count,
        ];
    }

    /**
     * Ringkasan piutang: sisa tagihan faktur diposting dan jumlah faktur yang lewat jatuh tempo.
     *
     * @return array{outstanding_total: string, overdue_count: int}
     */
    public function getSalesInvoicesSummary(): array
    {
        $data = DB::table('sales_invoices')
            ->whereIn('status', ['posted', 'partially_paid'])
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as outstanding_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN due_date < CURDATE() AND total > paid_amount THEN 1 ELSE 0 END), 0) as overdue_count')
            ->first();

        return [
            'outstanding_total' => Decimal::round((string) ($data->outstanding_total ?? '0'), Decimal::SCALE_MONEY),
            'overdue_count' => (int) ($data->overdue_count ?? 0),
        ];
    }

    /**
     * Ringkasan utang: sisa tagihan faktur pembelian diposting dan jumlah faktur yang lewat jatuh tempo.
     *
     * @return array{outstanding_total: string, overdue_count: int}
     */
    public function getPurchaseInvoicesSummary(): array
    {
        $data = DB::table('purchase_invoices')
            ->whereIn('status', ['posted', 'partially_paid'])
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as outstanding_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN due_date < CURDATE() AND total > paid_amount THEN 1 ELSE 0 END), 0) as overdue_count')
            ->first();

        return [
            'outstanding_total' => Decimal::round((string) ($data->outstanding_total ?? '0'), Decimal::SCALE_MONEY),
            'overdue_count' => (int) ($data->overdue_count ?? 0),
        ];
    }

    /**
     * Ringkasan stok minimum: jumlah produk di bawah min_stock & 5 produk paling kekurangan.
     *
     * @return array{low_stock_count: int, deficient_products: list<array{id: int, sku: string, name: string, unit_code: string, current_stock: string, min_stock: string, shortage: string}>}
     */
    public function getStockBalancesSummary(): array
    {
        // Query produk aktif track_stock dengan min_stock > 0
        // Dihitung per produk di seluruh gudang (produk tanpa baris saldo dihitung 0)
        $rows = DB::table('products as p')
            ->leftJoin('stock_balances as b', 'b.product_id', '=', 'p.id')
            ->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')
            ->where('p.is_active', true)
            ->where('p.track_stock', true)
            ->whereNull('p.deleted_at')
            ->where('p.min_stock', '>', 0)
            ->groupBy('p.id', 'p.sku', 'p.name', 'p.min_stock', 'u.code')
            ->selectRaw('p.id, p.sku, p.name, p.min_stock, COALESCE(u.code, "") as unit_code, COALESCE(SUM(b.quantity), 0) as current_stock, (p.min_stock - COALESCE(SUM(b.quantity), 0)) as shortage')
            ->havingRaw('shortage > 0')
            ->orderByDesc('shortage')
            ->get();

        $deficientProducts = $rows->take(5)->map(function ($row): array {
            return [
                'id' => (int) $row->id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'unit_code' => (string) $row->unit_code,
                'current_stock' => Decimal::round((string) $row->current_stock, Decimal::SCALE_QUANTITY),
                'min_stock' => Decimal::round((string) $row->min_stock, Decimal::SCALE_QUANTITY),
                'shortage' => Decimal::round((string) $row->shortage, Decimal::SCALE_QUANTITY),
            ];
        })->values()->all();

        return [
            'low_stock_count' => $rows->count(),
            'deficient_products' => $deficientProducts,
        ];
    }

    /**
     * 5 mutasi stok terakhir di seluruh gudang.
     *
     * @return list<array{id: int, movement_date: string, movement_type: string, product_sku: string, product_name: string, warehouse_code: string, warehouse_name: string, quantity: string, unit_cost: string}>
     */
    public function getRecentStockMovements(): array
    {
        return StockMovement::with(['product:id,sku,name', 'warehouse:id,code,name'])
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(function (StockMovement $m): array {
                return [
                    'id' => (int) $m->id,
                    'movement_date' => $m->movement_date->toISOString(),
                    'movement_type' => $m->movement_type->value,
                    'product_sku' => $m->product->sku,
                    'product_name' => $m->product->name,
                    'warehouse_code' => $m->warehouse->code,
                    'warehouse_name' => $m->warehouse->name,
                    'quantity' => Decimal::round((string) $m->quantity, Decimal::SCALE_QUANTITY),
                    'unit_cost' => Decimal::round((string) $m->unit_cost, Decimal::SCALE_COST),
                ];
            })
            ->all();
    }
}
