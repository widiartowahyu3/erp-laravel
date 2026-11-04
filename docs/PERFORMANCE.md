# Baseline Kinerja Database & Analisis EXPLAIN (Fase 7.5, Fase 7.6, & Fase 8A)

Dokumen ini menyajikan hasil pengujian performa query utama sistem ERP Distribusi / Trading pada dataset sintetis bervolume menengah/besar. Pengujian dilakukan menggunakan MariaDB 12.3 di dalam environment Docker terisolasi dengan perintah `EXPLAIN` dan `ANALYZE FORMAT=JSON`.

> [!NOTE] Lingkungan Pengujian & Skala Data Sintetis
> Pengujian dieksekusi melalui `database/seeders/PerformanceSeeder.php` pada database pengujian `erp_trading_test` (menolak berjalan di lingkungan produksi) dengan volume data:
> - **Produk (`products`)**: 5.000 baris
> - **Gudang (`warehouses`)**: 3 fasilitas gudang
> - **Mutasi Stok (`stock_movements`)**: 60.000 baris
> - **Saldo Stok (`stock_balances`)**: 5.000 baris konsisten
> - **Pesanan Pembelian (`purchase_orders`)**: 2.000 dokumen transaksi beserta ~8.000 baris item

---

## 1. Rangkuman Hasil Evaluasi Query Utama

Seluruh angka waktu dan statistik pembacaan halaman dikutip langsung dari berkas log keluaran mentah di direktori `review/f75b-explain-*.txt`, `review/f76-explain-dashboard-*.txt`, dan `review/f8a1-explain-after-*.txt`.

| No | Modul / Query | Access Type | Index yang Dipakai | Baris Discan (`r_rows`) | Filesort? | Waktu Eksekusi (`r_total_time_ms`) | Berkas Bukti Mentah |
|---|---|---|---|---|---|---|---|
| **1** | **Daftar Produk** (Cari `LIKE '%...%'`, filter kategori, urut nama) | `index` | `products_name_index` | 5.063 scan (73 match) | Tidak (manfaatkan index sort) | **2.65 ms** | `review/f75b-explain-products.txt` |
| **2** | **Saldo Stok** (Filter gudang, di bawah minimum stok) | `range` + `eq_ref` | `stock_balances_warehouse_id_foreign` + `PRIMARY` | 1.667 baris | Ya (`updated_at DESC`) | **23.48 ms** | `review/f75b-explain-stock-balances.txt` |
| **3** | **Mutasi Stok** (Listing default 25 mutasi) | `index` | `stock_movements_movement_date_id_index` | 25 baris | **Tidak** (0 filesort) | **0.16 ms** | `review/f8a1-explain-after-list.txt` |
| **4A**| **Kartu Stok - Saldo Awal** (Mutasi terakhir sebelum tanggal) | `range` | `stock_movements_product_id_warehouse_id_movement_date_index` | 1 baris | **Tidak** (0 filesort) | **0.23 ms** | `review/f75b-explain-stock-card.txt` |
| **4B**| **Kartu Stok - 50 Mutasi Periode** | `range` | `stock_movements_product_id_warehouse_id_movement_date_index` | 3 baris | **Tidak** (0 filesort) | **0.04 ms** | `review/f75b-explain-stock-card.txt` |
| **5** | **Daftar Pesanan Pembelian** (Filter status + pemasok, urut tanggal) | `ref` | `purchase_orders_supplier_id_status_index` | 334 baris | Ya (priority queue limit 25) | **5.80 ms** | `review/f75b-explain-purchase-orders.txt` |
| **6** | **Dashboard - Stok Minimum** (`getStockBalancesSummary`) | `ALL` + `ref` + `eq_ref` | `stock_balances_product_id_warehouse_id_unique` + `PRIMARY` | 5.000 produk + 5.000 saldo | Ya (temporary + filesort `shortage DESC`) | **122.61 ms** | `review/f76-explain-dashboard-stock-balances.txt` |
| **7** | **Dashboard - 5 Mutasi Terakhir** (`getRecentStockMovements`) | `index` | `stock_movements_movement_date_id_index` | 5 baris | **Tidak** (0 filesort) | **2.38 ms** | `review/f8a1-explain-after-dashboard.txt` |

---

## 2. Analisis Mendalam per Kasus Query

### A. Query 1: Pencarian Produk dengan Wildcard `LIKE '%...%'`
- **Pola Query**: Pencarian pada kolom `sku`, `name`, dan `barcode` dengan filter `category_id` dan pengurutan `name ASC, id ASC`.
- **Hasil EXPLAIN**: MariaDB memilih `access_type: index` menggunakan `products_name_index`. Hal ini dilakukan oleh optimizer MariaDB agar tidak perlu melakukan filesort di memori; optimizer memindai index pohon B-Tree yang sudah terurut berdasarkan nama, lalu melakukan filter kondisi `LIKE` dan `category_id` pada tiap baris.
- **Temuan Kinerja**: Pada 5.000 baris produk, waktu eksekusi adalah **2.65 ms** (sangat responsif). Hal ini membuktikan bahwa pada skala UMKM menengah (5.000 SKU), pencarian `LIKE '%term%'` dengan penataan index pengurutan yang tepat masih mampu melayani request di bawah batas toleransi pengguna (< 50 ms).

### B. Query 2: Saldo Stok per Gudang
- **Pola Query**: Filter `warehouse_id`, join ke `products` untuk memeriksa `b.quantity < p.min_stock`, diurutkan berdasarkan `b.updated_at DESC`.
- **Hasil EXPLAIN**: Index `warehouse_id` digunakan untuk membatasi 1.667 baris (dari total 5.000 baris saldo). Namun pengurutan `updated_at DESC` membutuhkan buffer filesort sebesar 801 KiB.
- **Temuan Kinerja**: Waktu eksekusi berada di **23.48 ms**. Meskipun masih di bawah batas toleransi, pemborosan I/O terjadi pada operasi filesort.

### C. Query 3: Mutasi Stok Default (Optimal Pasca Indeks Fase 8A)
- **Pola Query**: Listing 25 mutasi terakhir dari seluruh gudang dan produk (`ORDER BY movement_date DESC, id DESC LIMIT 25`).
- **Hasil EXPLAIN**: Berkat migrasi indeks `stock_movements_movement_date_id_index` pada Fase 8A Butir 1, MariaDB memilih `access_type: index` menggunakan indeks komposit tersebut.
- **Temuan Kinerja**: Pemindaian tabel penuh (Full Table Scan 60.000 baris) dan filesort yang sebelumnya memakan waktu ~100 ms berhasil dieliminasi sepenuhnya (`r_rows: 25`, tanpa filesort). Waktu eksekusi terpangkas menjadi **0.16 ms**.

### D. Query 4: Kartu Stok Buku Besar Persediaan
- **Pola Query**: Mengambil saldo awal sebelum `date_from` dan mengambil pergerakan dalam rentang tanggal untuk produk dan gudang tertentu.
- **Hasil EXPLAIN**: Memanfaatkan index komposit `stock_movements_product_id_warehouse_id_movement_date_index`.
- **Temuan Kinerja**: Kinerja luar biasa dengan waktu **0.23 ms** (saldo awal) dan **0.04 ms** (50 baris mutasi) tanpa operasi filesort (`0 filesort`). Index komposit yang dirancang pada Fase 7 terbukti tepat guna dan skalabel.

### E. Query 5: Daftar Pesanan Pembelian (PO)
- **Pola Query**: Filter `status = 'approved'` dan `supplier_id = 130`, diurutkan `order_date DESC`.
- **Hasil EXPLAIN**: Index komposit `purchase_orders_supplier_id_status_index` langsung memangkas ruang pencarian dari 2.000 PO menjadi 334 baris (`r_rows: 334`).
- **Temuan Kinerja**: Waktu eksekusi **5.80 ms**. Filesort hanya memproses 334 baris dengan struktur *priority queue* yang efisien.

### F. Query 6: Dashboard - Ringkasan Stok Minimum (`getStockBalancesSummary`)
- **Pola Query**: Menghitung produk aktif track-stock dengan `min_stock > 0`, LEFT JOIN ke `stock_balances` dan `units`, GROUP BY `p.id`, `p.sku`, `p.name`, `p.min_stock`, `u.code`, HAVING `shortage > 0`, ORDER BY `shortage DESC`.
- **Hasil EXPLAIN**: Pemindaian `products` menggunakan `access_type: ALL` (5.000 baris), join ke `stock_balances` via `ref` (`stock_balances_product_id_warehouse_id_unique`), join ke `units` via `eq_ref` (`PRIMARY`). Agregasi dan filter HAVING diselesaikan menggunakan tabel temporer di memori beserta filesort pada `shortage DESC`.
- **Temuan Kinerja**: Waktu eksekusi mencapai **122.61 ms** dengan 20.018 halaman InnoDB diakses untuk pembacaan saldo produk.

### G. Query 7: Dashboard - 5 Mutasi Stok Terakhir (`getRecentStockMovements`) (Optimal Pasca Indeks Fase 8A)
- **Pola Query**: Pengambilan 5 record mutasi stok terbaru di seluruh fasilitas: `SELECT * FROM stock_movements ORDER BY movement_date DESC, id DESC LIMIT 5`.
- **Hasil EXPLAIN**: Memanfaatkan indeks `stock_movements_movement_date_id_index` (`access_type: index`).
- **Temuan Kinerja**: Pemindaian terpangkas dari 60.000 baris menjadi hanya 5 baris (`r_rows: 5`) tanpa filesort. Waktu eksekusi terpangkas menjadi **2.38 ms** (dibandingkan sebelum indeks yang mencapai ~10-126 ms dengan priority queue filesort).

---

## 3. Usulan Indeks & Rekomendasi

### Usulan 1: Penambahan Index Komposit pada `stock_movements` (SELESAI - FASE 8A)
- **Tabel**: `stock_movements`
- **Status**: **Telah Diimplementasikan pada Fase 8A Butir 1** (`2026_11_01_000001_add_movement_date_id_index_to_stock_movements_table.php`).
- **Indeks**: `INDEX stock_movements_movement_date_id_index (movement_date, id)`
- **Manfaat**: Mengubah access type dari `ALL` (full scan 60.000 baris) menjadi `index` (hanya membaca baris pertama dari index tree sesuai LIMIT), menurunkan waktu eksekusi dari ~10-100 ms menjadi 0.16 - 2.38 ms baik untuk query listing mutasi maupun dashboard 5 mutasi terakhir.

### Usulan 2: Penambahan Composite Index pada `stock_balances`
- **Tabel**: `stock_balances`
- **Rekomendasi Indeks**: `INDEX idx_stock_balances_wh_updated (warehouse_id, updated_at DESC, id ASC)`
- **Manfaat**: Meniadakan filesort pada query saldo stok per fasilitas gudang, mengurangi konsumsi buffer memori (801 KiB per query).

### Usulan 3: Full-Text Search pada Entitas Master Data (Skala Lanjut)
- **Tabel**: `products`
- **Rekomendasi Indeks**: `FULLTEXT INDEX idx_products_search (sku, name, barcode)`
- **Manfaat**: Diperlukan apabila volume produk melampaui 50.000 SKU guna menggantikan klausa `LIKE '%...%'` dengan operator `MATCH(...) AGAINST(...)` dalam mode boolean.

### Usulan 4: Indeks untuk Filter Event pada `audit_logs` (Belum Diterapkan)
- **Tabel**: `audit_logs` (Fase ADM tidak boleh menambah migrasi).
- **Hasil EXPLAIN** pada data pengembangan 1.551 baris (`review/fadm1-5-explain.txt`): daftar bawaan dan rentang tanggal memakai `audit_logs_created_at_index`; filter pengguna memakai `audit_logs_user_id_created_at_index`; filter event dan tipe objek berjalan dengan menelusuri `audit_logs_created_at_index` lalu `Using where`.
- **Dampak**: filter event atau tipe objek yang jarang cocok dapat menelusuri banyak baris saat tabel membesar, karena kedua kolom itu tidak punya indeks untuk urutan terbaru dulu.
- **Rekomendasi Indeks**: `INDEX idx_audit_logs_event_created (event, created_at)`. Filter tipe objek memakai pencarian di tengah teks sehingga indeks biasa tidak membantu; bila perlu, ubah menjadi pilihan nilai persis dan pakai `INDEX idx_audit_logs_type_created (auditable_type, created_at)`.
