<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseOrderService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * DemoTransactionSeeder - Membangkitkan transaksi demo menggunakan domain service.
 *
 * Menggunakan PurchaseOrderService, GoodsReceiptService, dan StockAdjustmentService
 * agar penomoran dokumen, state machine, jejak audit, dan buku besar persediaan
 * 100% konsisten sesuai aturan domain bisnis.
 */
class DemoTransactionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Guard ketat: Larang eksekusi di lingkungan produksi
        if (app()->isProduction()) {
            throw new RuntimeException('DemoTransactionSeeder tidak boleh dijalankan di lingkungan production!');
        }

        // 2. Guard idempotensi: Jika transaksi PO sudah ada, lewati dengan pesan
        if (PurchaseOrder::query()->exists()) {
            $this->command?->info('Data transaksi demo sudah ada di database (dilewati).');

            return;
        }

        // 3. Pastikan Master Data tersedia (jalankan DemoMasterDataSeeder bila belum ada produk)
        if (Product::query()->count() === 0) {
            $this->call(DemoMasterDataSeeder::class);
        }

        $suppliers = Supplier::query()->where('is_active', true)->orderBy('id')->take(2)->get();
        $warehouses = Warehouse::query()->where('is_active', true)->orderBy('id')->take(2)->get();
        $products = Product::query()->where('is_active', true)->where('track_stock', true)->orderBy('id')->take(4)->get();

        if ($suppliers->count() < 1 || $warehouses->count() < 1 || $products->count() < 4) {
            throw new RuntimeException('Master data (supplier, warehouse, products) belum mencukupi untuk menjalankan DemoTransactionSeeder.');
        }

        $supplier1 = $suppliers[0];
        $supplier2 = $suppliers[1] ?? $supplier1;
        $warehouse1 = $warehouses[0];
        $warehouse2 = $warehouses[1] ?? $warehouse1;

        $p1 = $products[0];
        $p2 = $products[1];
        $p3 = $products[2];
        $p4 = $products[3];

        $poService = app(PurchaseOrderService::class);
        $grService = app(GoodsReceiptService::class);
        $saService = app(StockAdjustmentService::class);

        $today = Carbon::today();

        // =========================================================================
        // Skenario 1: PO 1 Disetujui -> Diterima Sebagian (1 GR Diposting)
        // =========================================================================
        $po1 = $poService->create([
            'supplier_id' => $supplier1->id,
            'warehouse_id' => $warehouse1->id,
            'order_date' => $today->copy()->subDays(5)->format('Y-m-d'),
            'expected_date' => $today->copy()->addDays(5)->format('Y-m-d'),
            'notes' => 'PO Demo Pengadaan Bahan Baku (Diterima Sebagian)',
            'items' => [
                [
                    'product_id' => $p1->id,
                    'quantity' => '20.0000',
                    'unit_price' => '50000.0000',
                    'discount_amount' => '0.00',
                    'tax_rate_id' => null,
                ],
                [
                    'product_id' => $p2->id,
                    'quantity' => '15.0000',
                    'unit_price' => '30000.0000',
                    'discount_amount' => '0.00',
                    'tax_rate_id' => null,
                ],
            ],
        ]);
        $po1 = $poService->approve($po1);

        $po1Items = $po1->items()->orderBy('line_no')->get();
        $gr1 = $grService->create([
            'purchase_order_id' => $po1->id,
            'supplier_id' => $po1->supplier_id,
            'warehouse_id' => $po1->warehouse_id,
            'receipt_date' => $today->copy()->subDays(2)->format('Y-m-d'),
            'notes' => 'Penerimaan Parsial Tahap 1 untuk PO Demo',
            'items' => [
                [
                    'purchase_order_item_id' => $po1Items[0]->id,
                    'product_id' => $p1->id,
                    'quantity' => '10.0000', // 10 dari 20
                ],
                [
                    'purchase_order_item_id' => $po1Items[1]->id,
                    'product_id' => $p2->id,
                    'quantity' => '5.0000', // 5 dari 15
                ],
            ],
        ]);
        $grService->post($gr1);

        // =========================================================================
        // Skenario 2: PO 2 Disetujui -> Diterima Penuh (2 GR Diposting)
        // =========================================================================
        $po2 = $poService->create([
            'supplier_id' => $supplier2->id,
            'warehouse_id' => $warehouse1->id,
            'order_date' => $today->copy()->subDays(10)->format('Y-m-d'),
            'expected_date' => $today->copy()->subDays(2)->format('Y-m-d'),
            'notes' => 'PO Demo Pengadaan Barang Dagang (Diterima Penuh bertahap)',
            'items' => [
                [
                    'product_id' => $p3->id,
                    'quantity' => '30.0000',
                    'unit_price' => '40000.0000',
                    'discount_amount' => '0.00',
                    'tax_rate_id' => null,
                ],
                [
                    'product_id' => $p4->id,
                    'quantity' => '20.0000',
                    'unit_price' => '25000.0000',
                    'discount_amount' => '0.00',
                    'tax_rate_id' => null,
                ],
            ],
        ]);
        $po2 = $poService->approve($po2);

        $po2Items = $po2->items()->orderBy('line_no')->get();
        // GR 2A (Penerimaan Pertama - Parsial)
        $gr2a = $grService->create([
            'purchase_order_id' => $po2->id,
            'supplier_id' => $po2->supplier_id,
            'warehouse_id' => $po2->warehouse_id,
            'receipt_date' => $today->copy()->subDays(4)->format('Y-m-d'),
            'notes' => 'Penerimaan Pengiriman Truk 1 (Parsial)',
            'items' => [
                [
                    'purchase_order_item_id' => $po2Items[0]->id,
                    'product_id' => $p3->id,
                    'quantity' => '15.0000',
                ],
                [
                    'purchase_order_item_id' => $po2Items[1]->id,
                    'product_id' => $p4->id,
                    'quantity' => '10.0000',
                ],
            ],
        ]);
        $grService->post($gr2a);

        // GR 2B (Penerimaan Kedua - Sisa/Penuh)
        $gr2b = $grService->create([
            'purchase_order_id' => $po2->id,
            'supplier_id' => $po2->supplier_id,
            'warehouse_id' => $po2->warehouse_id,
            'receipt_date' => $today->copy()->subDays(1)->format('Y-m-d'),
            'notes' => 'Penerimaan Pengiriman Truk 2 (Pelunasan Fisik Barang)',
            'items' => [
                [
                    'purchase_order_item_id' => $po2Items[0]->id,
                    'product_id' => $p3->id,
                    'quantity' => '15.0000',
                ],
                [
                    'purchase_order_item_id' => $po2Items[1]->id,
                    'product_id' => $p4->id,
                    'quantity' => '10.0000',
                ],
            ],
        ]);
        $grService->post($gr2b);

        // =========================================================================
        // Skenario 3: 1 PO Draf
        // =========================================================================
        $poService->create([
            'supplier_id' => $supplier1->id,
            'warehouse_id' => $warehouse2->id,
            'order_date' => $today->format('Y-m-d'),
            'notes' => 'PO Draf Perencanaan Pembelian Bulan Depan',
            'items' => [
                [
                    'product_id' => $p1->id,
                    'quantity' => '50.0000',
                    'unit_price' => '48000.0000',
                    'discount_amount' => '0.00',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        // =========================================================================
        // Skenario 4: 1 PO Dibatalkan (Void)
        // =========================================================================
        $po4 = $poService->create([
            'supplier_id' => $supplier2->id,
            'warehouse_id' => $warehouse1->id,
            'order_date' => $today->copy()->subDays(7)->format('Y-m-d'),
            'notes' => 'PO Draf yang dibatalkan karena kuota pemasok habis',
            'items' => [
                [
                    'product_id' => $p2->id,
                    'quantity' => '10.0000',
                    'unit_price' => '30000.0000',
                    'discount_amount' => '0.00',
                    'tax_rate_id' => null,
                ],
            ],
        ]);
        $poService->void($po4, 'Dibatalkan karena pemasok mengalami kendala logistik operasional');

        // =========================================================================
        // Skenario 5: Penyesuaian Stok (2 Posted: 1 Lebih, 1 Kurang, 1 Draf)
        // =========================================================================
        // SA 1: Penyesuaian Lebih (Counted > System) untuk p1 di warehouse1
        // Saldo saat ini p1 di warehouse1 adalah 10.0000. Disesuaikan menjadi 15.0000 (+5.0000).
        $sa1 = $saService->create([
            'warehouse_id' => $warehouse1->id,
            'adjustment_date' => $today->copy()->subDay()->format('Y-m-d'),
            'reason' => 'Stock opname menemukan kelebihan fisik 5 pcs',
            'notes' => 'Koreksi pencatatan opname fisik',
            'items' => [
                [
                    'product_id' => $p1->id,
                    'counted_quantity' => '15.0000',
                    'unit_cost' => '50000.0000',
                ],
            ],
        ]);
        $saService->post($sa1);

        // SA 2: Penyesuaian Kurang (Counted < System) untuk p2 di warehouse1
        // Saldo saat ini p2 di warehouse1 adalah 5.0000. Disesuaikan menjadi 2.0000 (-3.0000).
        // (Produk p2 memiliki min_stock > 2.0000 sehingga otomatis berada di bawah minimum stock).
        $sa2 = $saService->create([
            'warehouse_id' => $warehouse1->id,
            'adjustment_date' => $today->copy()->subDay()->format('Y-m-d'),
            'reason' => 'Penyesuaian pengurangan akibat barang rusak/kadaluwarsa',
            'notes' => 'Pemusnahan sampel uji',
            'items' => [
                [
                    'product_id' => $p2->id,
                    'counted_quantity' => '2.0000',
                ],
            ],
        ]);
        $saService->post($sa2);

        // SA 3: Penyesuaian Draf
        $saService->create([
            'warehouse_id' => $warehouse2->id,
            'adjustment_date' => $today->format('Y-m-d'),
            'reason' => 'Draf rencana stock opname gudang cabang',
            'notes' => 'Menunggu verifikasi supervisor gudang',
            'items' => [
                [
                    'product_id' => $p3->id,
                    'counted_quantity' => '10.0000',
                    'unit_cost' => '40000.0000',
                ],
            ],
        ]);

        $this->command?->info('Data demo transaksi (PO, GR, Penyesuaian Stok) berhasil di-seed.');
    }
}
