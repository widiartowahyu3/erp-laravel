# Desain dan Konvensi Database - ERP Distribusi/Trading

Dokumen ini mendefinisikan standar teknis, konvensi arsitektur data, kamus data ringkas, dan batasan integritas untuk seluruh 37 tabel domain pada proyek ERP Distribusi/Trading. Seluruh skema database diimplementasikan menggunakan migrasi Laravel terurut dengan MariaDB 12.3 (InnoDB, `utf8mb4_unicode_ci`).

---

## 1. Konvensi Desain Skema Database

1. **Storage Engine & Charset:**
   - Database Storage Engine: `InnoDB` untuk seluruh tabel.
   - Character Set: `utf8mb4`.
   - Collation: `utf8mb4_unicode_ci`.
2. **Primary Key (PK):**
   - Menggunakan kolom `id` dengan tipe `bigint unsigned auto-increment` untuk semua tabel, kecuali tabel pivot relasi many-to-many (`role_user` dan `permission_role`) yang menggunakan *composite primary key*.
3. **Presisi Tipe Numerik (Strictly No Float/Double):**
   - **Mata Uang / Jumlah Rupiah:** `decimal(18,2)` (misalnya: subtotal, diskon, pajak, total, amount, debit, credit).
   - **Kuantitas Barang:** `decimal(18,4)` (misalnya: quantity, received_quantity, delivered_quantity, system_quantity, counted_quantity, difference_quantity, min_stock).
   - **Harga Satuan & Biaya Satuan:** `decimal(18,4)` (misalnya: `unit_price`, `unit_cost`, `avg_cost`, `balance_after`, `avg_cost_after`).
   - **Persentase Pajak / Tarif:** `decimal(5,2)` (misalnya: `rate` pada `tax_rates`).
   - **DILARANG:** Penggunaan tipe data aproksimasi `float` maupun `double` di seluruh tabel domain untuk mencegah kesalahan pembulatan floating-point (*loss of precision*).
4. **Kebijakan Foreign Key (FK):**
   - Seluruh Foreign Key secara default wajib menerapkan:
     `ON UPDATE RESTRICT ON DELETE RESTRICT`
   - **Pengecualian Mutlak:** Hanya dua tabel pivot hak akses yang menerapkan `ON DELETE CASCADE`:
     - `role_user`: `ON DELETE CASCADE` saat role atau user dihapus.
     - `permission_role`: `ON DELETE CASCADE` saat permission atau role dihapus.
5. **Standar Kolom Audit (Audit Columns):**
   - Seluruh tabel domain (kecuali tabel append-only dan pivot murni) wajib memiliki kolom audit:
     - `created_at` (`timestamp null`)
     - `updated_at` (`timestamp null`)
     - `created_by` (`bigint unsigned null`, FK ke `users(id)` ON DELETE RESTRICT)
     - `updated_by` (`bigint unsigned null`, FK ke `users(id)` ON DELETE RESTRICT)
   - Tabel **append-only** (`audit_logs`, `stock_movements`) tidak memiliki `updated_at` maupun `updated_by`. Keduanya hanya memiliki `created_at` (+ `created_by` / `user_id`).
6. **Kebijakan Soft Delete vs Dokumen Transaksi:**
   - Kolom `deleted_at` (`softDeletes`) **HANYA** diizinkan pada data master:
     `users`, `units`, `product_categories`, `products`, `warehouses`, `suppliers`, `customers`, `tax_rates`, `payment_terms`, dan `chart_of_accounts`.
   - **DILARANG:** Soft delete pada dokumen transaksi atau tabel pergerakan data (`purchase_orders`, `goods_receipts`, `sales_orders`, `delivery_orders`, `stock_adjustments`, `journals`, `sales_invoices`, `purchase_invoices`, `payments`, `payment_allocations`, `stock_movements`, `audit_logs`).
   - Dokumen transaksi yang dibatalkan **TIDAK BOLEH DIHAPUS** (harus mempertahankan jejak audit dan nomor dokumen). Pembatalan dokumen dilakukan via status `void` dengan metadata:
     - `voided_at` (`timestamp null`)
     - `voided_by` (`bigint unsigned null`, FK ke `users(id)` ON DELETE RESTRICT)
     - `void_reason` (`varchar(255) null`)
7. **Standar Kolom Status & Constraint CHECK:**
   - Kolom status bertipe `varchar(20)`.
   - Wajib memiliki constraint `CHECK` bernama eksplisit `chk_<tabel>_status` yang memvalidasi whitelist nilai status yang sah.
   - Constraint `CHECK` didefinisikan menggunakan SQL literal statis (`DB::statement(...)`) di dalam file migrasi.
8. **Format Identifikasi Dokumen:**
   - Nomor dokumen transaksi (`number`) bertipe `varchar(30)` dan bernilai `UNIQUE`.
   - Format periode penomoran: `YYYYMM` (6 digit) pada tabel `document_sequences`.
9. **Penanganan Waktu:**
   - Tanggal operasional dokumen bisnis (`order_date`, `receipt_date`, `delivery_date`, `invoice_date`, `due_date`, `payment_date`, `adjustment_date`, `journal_date`): Menggunakan tipe `date`.
   - Waktu kejadian sistem / audit log / ledger pergerakan stok: Menggunakan tipe `datetime` atau `timestamp`.
10. **Penamaan Berkas Migrasi:**
    - Satu berkas migrasi per tabel.
    - Penomoran berkas migrasi terurut berdasarkan pohon dependensi FK (dimulai dari tabel independen/master hingga tabel transaksi anak).
    - Method `down()` wajib lengkap dan membalikkan struktur secara benar (drop trigger, drop check/foreign key, drop table).

---

## 2. Kamus Data Ringkas (37 Tabel Domain)

### A. Modul Administrasi & Keamanan
1. **`users`**
   - **Tujuan:** Menyimpan kredensial dan akun pengguna sistem.
   - **Kolom Kunci:** `id`, `name(100)`, `email(150)` (UNIQUE), `password(255)`, `is_active(bool)`, `last_login_at`, `remember_token(100)`, timestamps, `deleted_at`, `created_by`, `updated_by` (self FK).
2. **`roles`**
   - **Tujuan:** Definisi peran otorisasi pengguna (mis. Administrator, Admin Gudang, Kasir).
   - **Kolom Kunci:** `id`, `name(50)` (UNIQUE), `display_name(100)`, `description(255)`, `is_system(bool)`, timestamps, `created_by`, `updated_by`.
3. **`permissions`**
   - **Tujuan:** Hak akses granular fungsional sistem (format: `modul.aksi`, mis. `sales_order.create`).
   - **Kolom Kunci:** `id`, `name(100)` (UNIQUE), `module(50)`, `display_name(100)`, timestamps.
4. **`role_user`**
   - **Tujuan:** Pivot relasi many-to-many user dan role.
   - **Kolom Kunci:** Composite PK (`role_id`, `user_id`), FK CASCADE, `created_at`.
5. **`permission_role`**
   - **Tujuan:** Pivot relasi many-to-many role dan permission.
   - **Kolom Kunci:** Composite PK (`permission_id`, `role_id`), FK CASCADE.
6. **`audit_logs` (Append-Only)**
   - **Tujuan:** Jejak audit kepatuhan (*immutable audit trail*) atas perubahan data sistem.
   - **Kolom Kunci:** `id`, `user_id` (FK nullable), `event(50)`, `auditable_type(100)`, `auditable_id(unsigned bigint)`, `old_values(json)`, `new_values(json)`, `ip_address(45)`, `user_agent(255)`, `created_at`.
   - **Indeks:** `(auditable_type, auditable_id)`, `(user_id, created_at)`, `(created_at)`.
   - **Trigger:** Dilindungi trigger database anti-mutasi (UPDATE & DELETE ditolak).
7. **`document_sequences`**
   - **Tujuan:** Pengendali counter nomor dokumen transaksi bebas celah (*gapless sequence*).
   - **Kolom Kunci:** `id`, `doc_type(30)`, `period(6)` (YYYYMM), `prefix(10)`, `last_number(unsigned bigint)`, timestamps.
   - **Constraint:** UNIQUE (`doc_type`, `period`).
8. **`settings`**
   - **Tujuan:** Konfigurasi parameter operasional sistem level aplikasi.
   - **Kolom Kunci:** `id`, `key(100)` (UNIQUE), `value(text)`, `type(20)`, `group(50)`, `updated_by` (FK), timestamps.

### B. Modul Master Data
9. **`units`**
   - **Tujuan:** Satuan ukuran barang (UoM dasar, mis. PCS, BOX, KG, LTR, MTR).
   - **Kolom Kunci:** `id`, `code(10)` (UNIQUE), `name(50)`, timestamps, `deleted_at`, audit.
10. **`product_categories`**
    - **Tujuan:** Kategori hierarkis klasifikasi produk dagang.
    - **Kolom Kunci:** `id`, `parent_id` (self FK nullable), `code(30)` (UNIQUE), `name(100)`, timestamps, `deleted_at`, audit.
11. **`products`**
    - **Tujuan:** Master data barang dagang.
    - **Kolom Kunci:** `id`, `sku(50)` (UNIQUE), `name(150)`, `category_id` (FK), `base_unit_id` (FK `units`), `barcode(50)` (UNIQUE nullable), `description(text)`, `track_stock(bool)`, `selling_price(decimal 18,2)`, `min_stock(decimal 18,4)`, `is_active(bool)`, timestamps, `deleted_at`, audit.
    - **Constraint:** CHECK `selling_price >= 0` dan `min_stock >= 0`. Indeks: `(category_id)`, `(name)`.
12. **`warehouses`**
    - **Tujuan:** Lokasi penyimpanan fisik stok barang dagang.
    - **Kolom Kunci:** `id`, `code(20)` (UNIQUE), `name(100)`, `address(text)`, `is_active(bool)`, timestamps, `deleted_at`, audit.
13. **`suppliers`**
    - **Tujuan:** Master data pemasok/rekanan pengadaan.
    - **Kolom Kunci:** `id`, `code(30)` (UNIQUE), `name(150)`, `contact_name(100)`, `phone(30)`, `email(150)`, `address(text)`, `tax_id(30)`, `payment_term_id` (FK nullable), `is_active(bool)`, timestamps, `deleted_at`, audit.
14. **`customers`**
    - **Tujuan:** Master data pelanggan/pembeli.
    - **Kolom Kunci:** `id`, `code(30)` (UNIQUE), `name(150)`, `contact_name(100)`, `phone(30)`, `email(150)`, `address(text)`, `tax_id(30)`, `credit_limit(decimal 18,2)`, `payment_term_id` (FK nullable), `is_active(bool)`, timestamps, `deleted_at`, audit.
    - **Constraint:** CHECK `credit_limit >= 0`.
15. **`tax_rates`**
    - **Tujuan:** Master tarif pajak resmi (mis. PPN 0%).
    - **Kolom Kunci:** `id`, `code(20)` (UNIQUE), `name(100)`, `rate(decimal 5,2)`, `is_active(bool)`, timestamps, `deleted_at`, audit.
    - **Constraint:** CHECK `rate >= 0 AND rate <= 100`.
16. **`payment_terms`**
    - **Tujuan:** Termin pembayaran transaksi jual/beli (mis. CASH, NET7, NET30).
    - **Kolom Kunci:** `id`, `code(20)` (UNIQUE), `name(100)`, `days(smallint unsigned)`, timestamps, `deleted_at`, audit.

### C. Modul Pembelian (Procurement)
17. **`purchase_orders`**
    - **Tujuan:** Surat Pesanan Pembelian kepada pemasok.
    - **Kolom Kunci:** `id`, `number(30)` (UNIQUE), `supplier_id` (FK), `warehouse_id` (FK), `order_date(date)`, `expected_date(date nullable)`, `payment_term_id` (FK nullable), `status(20)` (CHECK: `draft`, `approved`, `partially_received`, `received`, `closed`, `void`), `subtotal`, `discount_amount`, `tax_amount`, `total` (decimal 18,2, CHECK >= 0), `notes(text)`, `approved_by` (FK), `approved_at`, `voided_by` (FK), `voided_at`, `void_reason`, timestamps, audit.
    - **Indeks:** `(supplier_id, status)`, `(order_date)`.
18. **`purchase_order_items`**
    - **Tujuan:** Rincian baris barang pesanan pembelian.
    - **Kolom Kunci:** `id`, `purchase_order_id` (FK), `line_no(smallint unsigned)`, `product_id` (FK), `quantity(decimal 18,4, CHECK > 0)`, `received_quantity(decimal 18,4, CHECK >= 0)`, `unit_price(decimal 18,4, CHECK >= 0)`, `discount_amount(decimal 18,2)`, `tax_rate_id` (FK nullable), `tax_amount(decimal 18,2)`, `line_total(decimal 18,2)`, timestamps.
    - **Constraint:** UNIQUE (`purchase_order_id`, `line_no`).
19. **`goods_receipts`**
    - **Tujuan:** Bukti penerimaan barang fisik di gudang dari pesanan pembelian.
    - **Kolom Kunci:** `id`, `number(30)` (UNIQUE), `purchase_order_id` (FK), `supplier_id` (FK), `warehouse_id` (FK), `receipt_date(date)`, `status(20)` (CHECK: `draft`, `posted`, `void`), `notes(text)`, `posted_by` (FK), `posted_at`, `voided_by` (FK), `voided_at`, `void_reason`, timestamps, audit.
    - **Indeks:** `(purchase_order_id)`, `(receipt_date)`.
20. **`goods_receipt_items`**
    - **Tujuan:** Rincian kuantitas fisik dan biaya satuan barang yang diterima.
    - **Kolom Kunci:** `id`, `goods_receipt_id` (FK), `line_no(smallint unsigned)`, `purchase_order_item_id` (FK), `product_id` (FK), `quantity(decimal 18,4, CHECK > 0)`, `unit_cost(decimal 18,4, CHECK >= 0)`, timestamps.
    - **Constraint:** UNIQUE (`goods_receipt_id`, `line_no`).

### D. Modul Inventori & Pergudangan
21. **`stock_balances`**
    - **Tujuan:** Saldo kuantitas dan rata-rata biaya per produk per gudang (current snapshot).
    - **Kolom Kunci:** `id`, `product_id` (FK), `warehouse_id` (FK), `quantity(decimal 18,4, CHECK >= 0)`, `avg_cost(decimal 18,4, CHECK >= 0)`, timestamps.
    - **Constraint:** UNIQUE (`product_id`, `warehouse_id`).
22. **`stock_movements` (Append-Only)**
    - **Tujuan:** Buku besar kartu stok barang (*immutable inventory ledger*).
    - **Kolom Kunci:** `id`, `movement_date(datetime)`, `product_id` (FK), `warehouse_id` (FK), `movement_type(30)` (CHECK: `receipt`, `delivery`, `adjustment_in`, `adjustment_out`), `quantity(decimal 18,4, CHECK <> 0, + masuk, - keluar)`, `unit_cost(decimal 18,4)`, `balance_after(decimal 18,4)`, `avg_cost_after(decimal 18,4)`, `source_type(50)`, `source_id(unsigned bigint)`, `created_by` (FK nullable), `created_at`.
    - **Indeks:** `(product_id, warehouse_id, movement_date)`, `(source_type, source_id)`.
    - **Trigger:** Dilindungi trigger database anti-mutasi (UPDATE & DELETE ditolak).
23. **`stock_adjustments`**
    - **Tujuan:** Dokumen penyesuaian/opname stok fisik gudang.
    - **Kolom Kunci:** `id`, `number(30)` (UNIQUE), `warehouse_id` (FK), `adjustment_date(date)`, `reason(255)`, `status(20)` (CHECK: `draft`, `posted`, `void`), `notes(text)`, `posted_by` (FK), `posted_at`, `voided_by` (FK), `voided_at`, `void_reason`, timestamps, audit.
24. **`stock_adjustment_items`**
    - **Tujuan:** Rincian selisih hitung fisik stok (sistem vs fisik).
    - **Kolom Kunci:** `id`, `stock_adjustment_id` (FK), `line_no(smallint unsigned)`, `product_id` (FK), `system_quantity(decimal 18,4)`, `counted_quantity(decimal 18,4, CHECK >= 0)`, `difference_quantity(decimal 18,4)`, `unit_cost(decimal 18,4)`, timestamps.
    - **Constraint:** UNIQUE (`stock_adjustment_id`, `line_no`).

### E. Modul Penjualan (Distribution & Sales)
25. **`sales_orders`**
    - **Tujuan:** Surat Pesanan Penjualan dari pelanggan.
    - **Kolom Kunci:** `id`, `number(30)` (UNIQUE), `customer_id` (FK), `warehouse_id` (FK), `order_date(date)`, `payment_term_id` (FK nullable), `status(20)` (CHECK: `draft`, `approved`, `partially_delivered`, `delivered`, `closed`, `void`), `subtotal`, `discount_amount`, `tax_amount`, `total` (decimal 18,2, CHECK >= 0), `notes(text)`, `approved_by` (FK), `approved_at`, `voided_by` (FK), `voided_at`, `void_reason`, timestamps, audit.
    - **Indeks:** `(customer_id, status)`, `(order_date)`.
26. **`sales_order_items`**
    - **Tujuan:** Rincian baris pesanan barang penjualan.
    - **Kolom Kunci:** `id`, `sales_order_id` (FK), `line_no(smallint unsigned)`, `product_id` (FK), `quantity(decimal 18,4, CHECK > 0)`, `delivered_quantity(decimal 18,4, CHECK >= 0)`, `unit_price(decimal 18,4, CHECK >= 0)`, `discount_amount(decimal 18,2)`, `tax_rate_id` (FK nullable), `tax_amount(decimal 18,2)`, `line_total(decimal 18,2)`, timestamps.
    - **Constraint:** UNIQUE (`sales_order_id`, `line_no`).
27. **`delivery_orders`**
    - **Tujuan:** Surat Jalan / Bukti Pengiriman barang fisik ke pelanggan.
    - **Kolom Kunci:** `id`, `number(30)` (UNIQUE), `sales_order_id` (FK), `customer_id` (FK), `warehouse_id` (FK), `delivery_date(date)`, `shipping_address(text)`, `status(20)` (CHECK: `draft`, `posted`, `void`), `notes(text)`, `posted_by` (FK), `posted_at`, `voided_by` (FK), `voided_at`, `void_reason`, timestamps, audit.
28. **`delivery_order_items`**
    - **Tujuan:** Rincian baris barang yang dikirim beserta snapshot HPP saat pengiriman.
    - **Kolom Kunci:** `id`, `delivery_order_id` (FK), `line_no(smallint unsigned)`, `sales_order_item_id` (FK), `product_id` (FK), `quantity(decimal 18,4, CHECK > 0)`, `unit_cost(decimal 18,4, snapshot HPP)`, timestamps.
    - **Constraint:** UNIQUE (`delivery_order_id`, `line_no`).

### F. Modul Keuangan & Akuntansi (Finance & General Ledger)
29. **`chart_of_accounts`**
    - **Tujuan:** Bagan Akun Standar (COA) pembukuan akuntansi berpasangan (*double-entry*).
    - **Kolom Kunci:** `id`, `code(20)` (UNIQUE), `name(150)`, `type(20)` (CHECK: `asset`, `liability`, `equity`, `revenue`, `expense`), `normal_balance(6)` (CHECK: `debit`, `credit`), `parent_id` (self FK nullable), `is_postable(bool)`, `is_active(bool)`, timestamps, `deleted_at`, audit.
30. **`journals`**
    - **Tujuan:** Header entri jurnal umum dan otomatisasi transaksi.
    - **Kolom Kunci:** `id`, `number(30)` (UNIQUE), `journal_date(date)`, `description(255)`, `source_type(50 nullable)`, `source_id(unsigned bigint nullable)`, `status(20)` (CHECK: `draft`, `posted`, `void`), `total_debit(decimal 18,2)`, `total_credit(decimal 18,2)`, `reversal_of_id` (self FK nullable), `posted_by` (FK), `posted_at`, `voided_by` (FK), `voided_at`, `void_reason`, timestamps, audit.
    - **Constraint:** CHECK `total_debit = total_credit`. Indeks: `(journal_date)`, `(source_type, source_id)`.
31. **`journal_lines`**
    - **Tujuan:** Rincian akun debit dan credit jurnal (*double-entry line items*).
    - **Kolom Kunci:** `id`, `journal_id` (FK), `line_no(smallint unsigned)`, `account_id` (FK `chart_of_accounts`), `debit(decimal 18,2)`, `credit(decimal 18,2)`, `description(255)`, timestamps.
    - **Constraint:** UNIQUE (`journal_id`, `line_no`), CHECK `(debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0) AND (debit + credit) > 0)`. Indeks: `(account_id)`.
32. **`sales_invoices`**
    - **Tujuan:** Faktur Tagihan Penjualan kepada pelanggan.
    - **Kolom Kunci:** `id`, `number(30)` (UNIQUE), `customer_id` (FK), `sales_order_id` (FK nullable), `invoice_date(date)`, `due_date(date)`, `status(20)` (CHECK: `draft`, `posted`, `partially_paid`, `paid`, `void`), `subtotal`, `discount_amount`, `tax_amount`, `total`, `paid_amount` (decimal 18,2), `journal_id` (FK nullable), `notes(text)`, `posted_by` (FK), `posted_at`, `voided_by` (FK), `voided_at`, `void_reason`, timestamps, audit.
    - **Constraint:** CHECK `paid_amount >= 0 AND paid_amount <= total`. Indeks: `(customer_id, status)`, `(due_date)`.
33. **`sales_invoice_items`**
    - **Tujuan:** Rincian baris barang/layanan pada faktur penjualan.
    - **Kolom Kunci:** `id`, `sales_invoice_id` (FK), `line_no(smallint unsigned)`, `product_id` (FK nullable), `delivery_order_item_id` (FK nullable), `description(255)`, `quantity(decimal 18,4, CHECK > 0)`, `unit_price(decimal 18,4)`, `discount_amount(decimal 18,2)`, `tax_rate_id` (FK nullable), `tax_amount(decimal 18,2)`, `line_total(decimal 18,2)`, timestamps.
    - **Constraint:** UNIQUE (`sales_invoice_id`, `line_no`).
34. **`purchase_invoices`**
    - **Tujuan:** Tagihan Faktur Pembelian dari pemasok.
    - **Kolom Kunci:** `id`, `number(30)` (UNIQUE), `supplier_id` (FK), `purchase_order_id` (FK nullable), `supplier_invoice_number(50)`, `invoice_date(date)`, `due_date(date)`, `status(20)` (CHECK: `draft`, `posted`, `partially_paid`, `paid`, `void`), `subtotal`, `discount_amount`, `tax_amount`, `total`, `paid_amount` (decimal 18,2), `journal_id` (FK nullable), `notes(text)`, `posted_by` (FK), `posted_at`, `voided_by` (FK), `voided_at`, `void_reason`, timestamps, audit.
    - **Constraint:** UNIQUE (`supplier_id`, `supplier_invoice_number`), CHECK `paid_amount >= 0 AND paid_amount <= total`. Indeks: `(supplier_id, status)`, `(due_date)`.
35. **`purchase_invoice_items`**
    - **Tujuan:** Rincian baris barang/tagihan pada faktur pembelian.
    - **Kolom Kunci:** `id`, `purchase_invoice_id` (FK), `line_no(smallint unsigned)`, `product_id` (FK nullable), `goods_receipt_item_id` (FK nullable), `description(255)`, `quantity(decimal 18,4, CHECK > 0)`, `unit_price(decimal 18,4)`, `discount_amount(decimal 18,2)`, `tax_rate_id` (FK nullable), `tax_amount(decimal 18,2)`, `line_total(decimal 18,2)`, timestamps.
    - **Constraint:** UNIQUE (`purchase_invoice_id`, `line_no`).
36. **`payments`**
    - **Tujuan:** Transaksi kas/bank penerimaan pembayaran piutang atau pengeluaran utang.
    - **Kolom Kunci:** `id`, `number(30)` (UNIQUE), `payment_type(20)` (CHECK: `receipt`, `disbursement`), `customer_id` (FK nullable), `supplier_id` (FK nullable), `payment_date(date)`, `method(30)` (CHECK: `cash`, `bank_transfer`, `other`), `account_id` (FK `chart_of_accounts`), `amount(decimal 18,2, CHECK > 0)`, `reference(100)`, `status(20)` (CHECK: `draft`, `posted`, `void`), `journal_id` (FK nullable), `notes(text)`, `posted_by` (FK), `posted_at`, `voided_by` (FK), `voided_at`, `void_reason`, timestamps, audit.
    - **Constraint:** CHECK `(payment_type='receipt' AND customer_id IS NOT NULL AND supplier_id IS NULL) OR (payment_type='disbursement' AND supplier_id IS NOT NULL AND customer_id IS NULL)`.
37. **`payment_allocations`**
    - **Tujuan:** Alokasi pembayaran terhadap faktur penjualan (piutang) atau faktur pembelian (utang).
    - **Kolom Kunci:** `id`, `payment_id` (FK), `sales_invoice_id` (FK nullable), `purchase_invoice_id` (FK nullable), `amount(decimal 18,2, CHECK > 0)`, timestamps.
    - **Constraint:** CHECK tepat satu dari `sales_invoice_id` atau `purchase_invoice_id` terisi, UNIQUE (`payment_id`, `sales_invoice_id`), UNIQUE (`payment_id`, `purchase_invoice_id`).

---

## 3. Aturan Desain & Prinsip Integritas Arsitektur

### A. Ledger Stok Append-Only & Saldo Stok Moving Average
1. **Pemisahan Snapshot & Ledger Historis:**
   - Tabel `stock_balances` berfungsi sebagai cache/snapshot saldo instan (*fast read*) per produk per gudang.
   - Tabel `stock_movements` berfungsi sebagai buku besar (*general inventory ledger*) yang bersifat **append-only** (hanya `INSERT` dan `SELECT`).
2. **Kalkulasi Biaya Rata-Rata Bergerak (*Moving Average Cost*):**
   - Setiap mutasi masuk (`receipt`, `adjustment_in`), nilai rata-rata baru dihitung secara atomik:
     $$\text{avg\_cost}_{\text{baru}} = \frac{(\text{qty}_{\text{lama}} \times \text{cost}_{\text{lama}}) + (\text{qty}_{\text{masuk}} \times \text{cost}_{\text{masuk}})}{\text{qty}_{\text{lama}} + \text{qty}_{\text{masuk}}}$$
   - Setiap mutasi keluar (`delivery`, `adjustment_out`), barang dikeluarkan dengan biaya satuan sebesar `avg_cost` saat mutasi terjadi tanpa mengubah `avg_cost` saldo tersisa.
3. **Pemberlakuan Locking Konkurensi (Fase 7):**
   - Pembaruan saldo `stock_balances` dan penambahan baris `stock_movements` wajib dieksekusi di dalam satu database transaction dengan *pessimistic locking* (`SELECT ... FOR UPDATE` pada baris `stock_balances`).

### B. Penomoran Dokumen Otomatis Bebas Celah (*Gapless Sequences*)
- Penomoran dokumen transaksi bisnis (`PO`, `GR`, `SO`, `DO`, `SA`, `SI`, `PI`, `PAY`, `JV`) dikelola terpusat via tabel `document_sequences`.
- Format penomoran dokumen: `[PREFIX]/[YYYYMM]/[NOMOR_URUT_5_DIGIT]` (contoh: `SO/202610/00001`).
- Pada implementasi service (Fase 4), generasi nomor dokumen dilakukan di dalam transaksi dengan baris counter di-lock menggunakan `SELECT ... FOR UPDATE` berdasarkan kombinasi (`doc_type`, `period`).

### C. Alur Status Dokumen & Larangan Penghapusan Transaksi
1. **Siklus Hidup Dokumen Transaksi:**
   - `draft` $\rightarrow$ `approved` (atau `posted`) $\rightarrow$ `closed` (atau `void`).
2. **Alasan Mengapa Transaksi Tidak Dihapus:**
   - Dalam sistem ERP bersertifikasi kepatuhan dan audit akuntansi, penghapusan data transaksi (baik hard delete maupun soft delete) adalah pelanggaran berat yang merusak rantai audit (*audit trail break*), mengacaukan nomor seri faktur, dan merusak buku besar akuntansi (*ledger mismatch*).
   - Seluruh koreksi atau pembatalan transaksi dilakukan secara transparan melalui:
     - Perubahan status dokumen menjadi `void` (merekam siapa yang membatalkan, waktu pembatalan, dan alasan pembatalan).
     - Pembuatan jurnal pembalik (*reversal journal*) yang mereferensikan jurnal asal (`reversal_of_id`).
     - Pembuatan mutasi penyesuaian inventori korektif bila barang fisik sudah terlanjur bergerak.

### D. Kebijakan Integritas Relasional (`ON DELETE RESTRICT`)
- Seluruh relasi Foreign Key antar data master dan transaksi dilindungi secara ketat dengan `ON DELETE RESTRICT` dan `ON UPDATE RESTRICT`.
- Tidak ada data master (produk, gudang, pelanggan, pemasok, akun COA) yang dapat dihapus dari database selama masih terdapat riwayat transaksi yang mereferensikannya.

### E. Batasan Sengaja (*Deliberate Architectural Boundaries*)
Untuk mempertahankan fokus domain, kesederhanaan model, dan keandalan skema pada versi rilis ini:
1. **Satu Satuan Dasar per Produk:** Setiap produk hanya memiliki satu satuan inventori dasar (`base_unit_id`). Tidak ada konversi multi-satuan bertingkat dinamis di tingkat database.
2. **Tanpa Transfer Antar Gudang:** Transaksi pemindahan stok antar gudang diselesaikan melalui kombinasi dokumen `adjustment_out` pada gudang asal dan `adjustment_in` pada gudang tujuan.
3. **Mata Uang Tunggal (IDR):** Sistem beroperasi secara ketat dalam mata uang tunggal Rupiah Indonesia (IDR). Seluruh kolom uang bertipe `decimal(18,2)` tanpa tabel kurs multi-mata-uang.

### F. Data Awal Resmi (Seeders Referensi Baku)
Sistem menyediakan seeder referensi idempoten berbasis query builder (`upsert`) tanpa membuat kredensial atau akun pengguna bawaan:
1. **Satuan Ukuran (`UnitSeeder`)**: 5 satuan baku:
   - `PCS` (Pcs)
   - `BOX` (Box)
   - `KG` (Kilogram)
   - `LTR` (Liter)
   - `MTR` (Meter)
2. **Syarat Pembayaran (`PaymentTermSeeder`)**: 4 termin pembayaran:
   - `CASH` (Tunai, 0 hari)
   - `NET7` (Net 7 Hari, 7 hari)
   - `NET14` (Net 14 Hari, 14 hari)
   - `NET30` (Net 30 Hari, 30 hari)
3. **Tarif Pajak (`TaxRateSeeder`)**: 1 tarif pajak non-aktif/bebas:
   - `NOL` (Bebas Pajak / 0%, tarif 0.00%)
4. **Bagan Akun Standar (`ChartOfAccountSeeder`)**: Tepat 20 akun COA terstruktur hierarkis (6 akun header non-postable dan 14 akun detail postable):
   - Aset (1000): Kas Utama (1101), Bank Operasional (1102), Piutang Usaha (1201), Persediaan Barang Dagang (1301)
   - Kewajiban (2000): Utang Usaha (2101), Utang Pajak PPN (2102)
   - Ekuitas (3000): Modal Pemilik (3101), Laba Ditahan (3201)
   - Pendapatan (4000): Pendapatan Penjualan (4101), Diskon Penjualan (4102)
   - Beban Pokok Penjualan (5000): Beban Pokok Penjualan (5101), Penyesuaian Nilai Persediaan (5102)
   - Beban Operasional (6000): Beban Operasional & Umum (6101), Beban Administrasi Bank (6102)

