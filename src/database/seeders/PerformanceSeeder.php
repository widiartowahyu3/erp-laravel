<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PerformanceSeeder - Seeder data sintetis untuk pengujian performa & EXPLAIN.
 *
 * PERINGATAN:
 * Berkas ini HANYA boleh dijalankan pada lingkungan non-production DAN database uji (_test).
 * Data di dalam seeder ini murni bersifat sintetis bervolume besar untuk evaluasi EXPLAIN,
 * BUKAN data transaksi demo realistis untuk bisnis.
 */
class PerformanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Guard ketat: Tolak eksekusi di lingkungan produksi
        if (app()->isProduction()) {
            throw new RuntimeException('PerformanceSeeder DILARANG dijalankan di lingkungan produksi!');
        }

        // 2. Guard ketat: Tolak eksekusi jika nama database tidak berakhiran _test
        $activeDb = (string) DB::connection()->getDatabaseName();
        if (! str_ends_with($activeDb, '_test')) {
            throw new RuntimeException("PerformanceSeeder HANYA diizinkan pada database pengujian berakhiran '_test'. Database aktif: {$activeDb}");
        }

        $now = now();

        // 3. Pastikan data master prasyarat tersedia di database uji
        $this->ensurePrerequisites($now);

        $categoryIds = DB::table('product_categories')->pluck('id')->all();
        $unitId = (int) DB::table('units')->value('id');
        $warehouseIds = DB::table('warehouses')->orderBy('id')->pluck('id')->all();
        $supplierIds = DB::table('suppliers')->pluck('id')->all();

        // 4. Buat 5.000 Produk Sintetis
        echo "[PERF SEEDER] Menghasilkan 5.000 produk sintetis...\n";
        $productCount = 5000;
        $batchSize = 1000;
        $productRows = [];

        for ($i = 1; $i <= $productCount; $i++) {
            $padded = str_pad((string) $i, 5, '0', STR_PAD_LEFT);
            $catId = $categoryIds[($i - 1) % count($categoryIds)];

            $productRows[] = [
                'sku' => "SKU-PERF-{$padded}",
                'name' => "Produk Sintetis Benchmark {$padded}",
                'category_id' => $catId,
                'base_unit_id' => $unitId,
                'barcode' => "BARCODE-PERF-{$padded}",
                'description' => "Deskripsi sintetis produk benchmark {$padded}",
                'track_stock' => 1,
                'selling_price' => '50000.00',
                'min_stock' => ($i % 5 === 0) ? '100.0000' : '10.0000',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($productRows) >= $batchSize) {
                DB::table('products')->insert($productRows);
                $productRows = [];
            }
        }
        if (! empty($productRows)) {
            DB::table('products')->insert($productRows);
        }

        $productIds = DB::table('products')->where('sku', 'like', 'SKU-PERF-%')->orderBy('id')->pluck('id')->all();

        // 5. Buat 60.000 stock_movements dengan stock_balances yang konsisten
        // 5.000 produk * 12 mutasi per produk = 60.000 mutasi
        echo "[PERF SEEDER] Menghasilkan 60.000 stock_movements dan stock_balances konsisten...\n";
        $movementRows = [];
        $balanceRows = [];

        foreach ($productIds as $idx => $prodId) {
            $whId = $warehouseIds[$idx % count($warehouseIds)];

            $currentBalance = '0.0000';
            $currentAvgCost = '20000.0000';

            // 12 mutasi per produk: selang-seling receipt dan delivery
            for ($m = 1; $m <= 12; $m++) {
                $isReceipt = ($m % 2 === 1);
                $type = $isReceipt ? 'receipt' : 'delivery';
                $qty = $isReceipt ? '50.0000' : '-10.0000';
                $unitCost = $isReceipt ? (string) (20000 + ($m * 500)).'.0000' : $currentAvgCost;

                if ($isReceipt) {
                    $newBalance = bcadd($currentBalance, '50.0000', 4);
                    // Rata-rata bergerak sintetis
                    $newAvgCost = $currentAvgCost;
                } else {
                    $newBalance = bcsub($currentBalance, '10.0000', 4);
                    $newAvgCost = $currentAvgCost;
                }

                $currentBalance = $newBalance;
                $currentAvgCost = $newAvgCost;

                $movementRows[] = [
                    'movement_date' => $now->copy()->subDays(60 - ($m * 5)),
                    'product_id' => $prodId,
                    'warehouse_id' => $whId,
                    'movement_type' => $type,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'balance_after' => $currentBalance,
                    'avg_cost_after' => $currentAvgCost,
                    'source_type' => 'App\\Models\\Purchasing\\GoodsReceipt',
                    'source_id' => $prodId,
                    'created_by' => null,
                    'created_at' => $now,
                ];

                if (count($movementRows) >= $batchSize) {
                    DB::table('stock_movements')->insert($movementRows);
                    $movementRows = [];
                }
            }

            // Catat saldo konsisten
            $balanceRows[] = [
                'product_id' => $prodId,
                'warehouse_id' => $whId,
                'quantity' => $currentBalance,
                'avg_cost' => $currentAvgCost,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($balanceRows) >= $batchSize) {
                DB::table('stock_balances')->insert($balanceRows);
                $balanceRows = [];
            }
        }

        if (! empty($movementRows)) {
            DB::table('stock_movements')->insert($movementRows);
        }
        if (! empty($balanceRows)) {
            DB::table('stock_balances')->insert($balanceRows);
        }

        // 6. Buat 2.000 Purchase Orders (dengan 2-6 baris item tiap PO)
        echo "[PERF SEEDER] Menghasilkan 2.000 purchase orders beserta item...\n";
        $poCount = 2000;
        $poRows = [];
        $statuses = ['approved', 'partially_received', 'received', 'draft', 'closed', 'void'];

        for ($p = 1; $p <= $poCount; $p++) {
            $paddedPo = str_pad((string) $p, 5, '0', STR_PAD_LEFT);
            $suppId = $supplierIds[($p - 1) % count($supplierIds)];
            $whId = $warehouseIds[($p - 1) % count($warehouseIds)];
            $st = $statuses[($p - 1) % count($statuses)];

            $poRows[] = [
                'number' => "PO/PERF/{$paddedPo}",
                'supplier_id' => $suppId,
                'warehouse_id' => $whId,
                'order_date' => $now->copy()->subDays($p % 90)->format('Y-m-d'),
                'expected_date' => $now->copy()->addDays(7)->format('Y-m-d'),
                'payment_term_id' => null,
                'status' => $st,
                'subtotal' => '200000.00',
                'discount_amount' => '0.00',
                'tax_amount' => '22000.00',
                'total' => '222000.00',
                'notes' => "Sintetis PO {$paddedPo}",
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($poRows) >= $batchSize) {
                DB::table('purchase_orders')->insert($poRows);
                $poRows = [];
            }
        }
        if (! empty($poRows)) {
            DB::table('purchase_orders')->insert($poRows);
        }

        $poList = DB::table('purchase_orders')->where('number', 'like', 'PO/PERF/%')->orderBy('id')->pluck('id')->all();
        $poItemRows = [];

        foreach ($poList as $poIdx => $pId) {
            $lines = ($poIdx % 5) + 2; // 2 sampai 6 baris
            for ($ln = 1; $ln <= $lines; $ln++) {
                $itemProdId = $productIds[($poIdx * 3 + $ln) % count($productIds)];

                $poItemRows[] = [
                    'purchase_order_id' => $pId,
                    'line_no' => $ln,
                    'product_id' => $itemProdId,
                    'quantity' => '10.0000',
                    'received_quantity' => '0.0000',
                    'unit_price' => '20000.0000',
                    'discount_amount' => '0.00',
                    'tax_rate_id' => null,
                    'tax_amount' => '2200.00',
                    'line_total' => '22200.00',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($poItemRows) >= $batchSize) {
                    DB::table('purchase_order_items')->insert($poItemRows);
                    $poItemRows = [];
                }
            }
        }
        if (! empty($poItemRows)) {
            DB::table('purchase_order_items')->insert($poItemRows);
        }

        echo "[PERF SEEDER] Selesai: 5.000 produk, 60.000 mutasi stok, 2.000 PO berhasil diisi.\n";
    }

    /**
     * Pastikan tabel master pendukung memiliki minimal entri.
     */
    private function ensurePrerequisites(CarbonInterface $now): void
    {
        // 1. Units
        if (DB::table('units')->count() === 0) {
            DB::table('units')->insert([
                ['code' => 'PCS', 'name' => 'Pieces', 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        // 2. Categories
        if (DB::table('product_categories')->count() === 0) {
            DB::table('product_categories')->insert([
                ['code' => 'CAT-01', 'name' => 'Elektronik', 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'CAT-02', 'name' => 'Alat Tulis', 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'CAT-03', 'name' => 'Perabotan', 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        // 3. Warehouses
        $whCount = DB::table('warehouses')->count();
        if ($whCount < 3) {
            for ($w = $whCount + 1; $w <= 3; $w++) {
                DB::table('warehouses')->insert([
                    'code' => "WH-0{$w}",
                    'name' => "Gudang Cabang 0{$w}",
                    'address' => "Alamat Gudang 0{$w}",
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // 4. Suppliers
        if (DB::table('suppliers')->count() === 0) {
            DB::table('suppliers')->insert([
                ['code' => 'SUP-01', 'name' => 'Supplier Alpha', 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'SUP-02', 'name' => 'Supplier Beta', 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'SUP-03', 'name' => 'Supplier Gamma', 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }
}
