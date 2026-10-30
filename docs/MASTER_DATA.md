# Modul Master Data ERP Distribusi / Trading (Fase 6)

Dokumen ini menjelaskan spesifikasi arsitektur, standar teknis, model relasional, aturan bisnis, dan antarmuka pengguna untuk 8 entitas master data pada aplikasi ERP Distribusi/Trading.

---

## 1. Lingkup Entitas Master Data (8 Entitas)

Sesuai dengan skema database 37 tabel hasil Fase 2, modul Master Data Fase 6 mencakup 8 entitas utama:

| No | Modul / Entitas | Tabel Database | Primary Key | Deskripsi Singkat |
|---|---|---|---|---|
| 1 | **Satuan Ukuran** | `units` | `id` (BIGINT UNSIGNED) | Satuan unit barang/produk (mis. PCS, BOX, DUS, KG) |
| 2 | **Kategori Produk** | `product_categories` | `id` (BIGINT UNSIGNED) | Hirarki kategori produk bertingkat (*self-referencing parent_id*) |
| 3 | **Gudang** | `warehouses` | `id` (BIGINT UNSIGNED) | Lokasi fisik penyimpanan dan pergerakan stok barang |
| 4 | **Tarif Pajak** | `tax_rates` | `id` (BIGINT UNSIGNED) | Tarif persentase perpajakan (PPN, PPh, dll., rentang 0–100%) |
| 5 | **Syarat Pembayaran** | `payment_terms` | `id` (BIGINT UNSIGNED) | Termin jatuh tempo pembayaran dalam hari (mis. COD, NET 30) |
| 6 | **Pemasok** | `suppliers` | `id` (BIGINT UNSIGNED) | Data rekanan vendor / supplier pembelian barang dagang |
| 7 | **Pelanggan** | `customers` | `id` (BIGINT UNSIGNED) | Data pelanggan / customer penjualan barang |
| 8 | **Produk** | `products` | `id` (BIGINT UNSIGNED) | Katalog master barang dagang beserta harga beli, jual, dan unit dasar |

> [!NOTE]
> Modul master data Fase 6 strictly dibatasi pada 8 entitas di atas. Dokumen transaksi operasional (PO, SO, GR, DO, Faktur, Pembayaran, Jurnal, dan Mutasi Stok) serta manajemen pengguna/role berada di luar lingkup Fase 6.

---

## 2. Arsitektur Backend & Pola Service Master Data

Seluruh interaksi master data mengikuti pola arsitektur berlapis baku:

```text
[HTTP Request]
       │
       ▼
[Controller] (MasterData/*Controller)
       │
       ▼
[FormRequest] (*StoreRequest / *UpdateRequest) ──► Validasi Format & Soft-Delete Unique
       │
       ▼
[Service Layer] (*Service) ──────────────────────► Transaksi DB, Normalisasi, & Aturan Bisnis
       │
       ├──► [ReferenceGuard] ────────────────────► Cek Ketergantungan Downstream (409 Conflict)
       │
       ▼
[Eloquent Model] ────────────────────────────────► RecordsAudit & HasAuditUsers
       │
       ▼
[MariaDB Database] ──────────────────────────────► CHECK Constraints, FK RESTRICT, Triggers
```

### 1. Form Requests (`App\Http\Requests\MasterData`)
- Meng-extend `BaseMasterDataRequest` yang mewarisi `authorize(): bool { return true; }` (karena otorisasi ditegakkan pada route middleware `can:`).
- Menerapkan sanitasi input otomatis: string di-`trim()`, kode di-`strtoupper()`, kolom opsional kosong dijadikan `null`.
- Validasi keunikan (*unique check*) mencakup seluruh baris termasuk data yang telah dihapus (*soft-deleted*):
  ```php
  Rule::unique('units', 'code')
  ```
  Pada update request, ID entitas saat ini diabaikan secara eksplisit:
  ```php
  Rule::unique('units', 'code')->ignore($id)
  ```
  Hal ini selaras dengan batasan `UNIQUE KEY` MariaDB yang tidak memiliki klausul parsial `WHERE deleted_at IS NULL`, sehingga kode/SKU/barcode unik yang pernah digunakan tidak dapat dipakai kembali sekalipun telah di-soft-delete. Apabila terjadi kondisi balapan (*race condition*) saat insert/update bersamaan pada level database, exception `UniqueConstraintViolationException` ditangkap secara terpusat di `bootstrap/app.php` dan dikonversi menjadi error validasi 422 inline pada form field terkait.
- Validasi numerik mata uang/kuantitas menggunakan custom rule `DecimalString`:
  - Format angka desimal strictly tervalidasi via regex tanpa konversi floating-point PHP.
  - Memastikan angka bernilai non-negatif dan presisi desimal sesuai peruntukan (maks. 2 desimal untuk uang, 4 untuk kuantitas/stok, 2 untuk tarif pajak).

### 2. Service Layer (`App\Services\MasterData`)
- Bertanggung jawab atas operasi mutasi data (`create`, `update`, `delete`).
- Seluruh mutasi dijalankan di dalam blok transaksi `DB::transaction()` untuk menjamin atomisitas.
- Operasi `delete()` selalu memanggil `ReferenceGuard::assertCanDelete($model, $moduleLabel)` sebelum menjalankan `$model->delete()`.
- Mutasi model diberi anotasi `// @audit-bypass: Audited via RecordsAudit model event` untuk mematuhi arsitektur pengawasan audit.

---

## 3. Matriks ReferenceGuard & Pencegahan Penghapusan Data

Untuk mencegah inkonsistensi data transaksi hilir dan pelanggaran Foreign Key database yang membingungkan pengguna, class `App\Services\MasterData\ReferenceGuard` memvalidasi keberadaan rekaman relasional hilir sebelum soft-delete dijalankan.

Jika rekaman ditemukan, `ReferenceGuard` melempar `BusinessRuleException` dengan HTTP status **409 Conflict** dan pesan human-readable dalam Bahasa Indonesia:
`"Tidak dapat menghapus [Modul] karena masih digunakan oleh data [Label Relasi]."`

### Matriks Relasi yang Dilindungi:

| Entitas Master | Tabel Hilir yang Diperiksa | Kolom Foreign Key | Label Pesan Error |
|---|---|---|---|
| **units** | `products` | `base_unit_id` | Produk |
| **product_categories** | `product_categories`<br>`products` | `parent_id`<br>`category_id` | Subkategori Produk<br>Produk |
| **products** | `purchase_order_items`<br>`goods_receipt_items`<br>`sales_order_items`<br>`delivery_order_items`<br>`purchase_invoice_items`<br>`sales_invoice_items`<br>`stock_balances`<br>`stock_movements`<br>`stock_adjustment_items` | `product_id` | Pesanan Pembelian<br>Penerimaan Barang<br>Pesanan Penjualan<br>Surat Jalan / Pengiriman<br>Faktur Pembelian<br>Faktur Penjualan<br>Saldo Stok<br>Mutasi Stok<br>Penyesuaian Stok |
| **warehouses** | `purchase_orders`<br>`goods_receipts`<br>`sales_orders`<br>`delivery_orders`<br>`stock_balances`<br>`stock_movements`<br>`stock_adjustments` | `warehouse_id` | Pesanan Pembelian<br>Penerimaan Barang<br>Pesanan Penjualan<br>Surat Jalan / Pengiriman<br>Saldo Stok<br>Mutasi Stok<br>Penyesuaian Stok |
| **suppliers** | `purchase_orders`<br>`goods_receipts`<br>`purchase_invoices`<br>`payments` | `supplier_id` | Pesanan Pembelian<br>Penerimaan Barang<br>Faktur Pembelian<br>Pembayaran Kas/Bank |
| **customers** | `sales_orders`<br>`delivery_orders`<br>`sales_invoices`<br>`payments` | `customer_id` | Pesanan Penjualan<br>Surat Jalan / Pengiriman<br>Faktur Penjualan<br>Pembayaran Kas/Bank |
| **tax_rates** | `purchase_order_items`<br>`sales_order_items`<br>`purchase_invoice_items`<br>`sales_invoice_items` | `tax_rate_id` | Pesanan Pembelian<br>Pesanan Penjualan<br>Faktur Pembelian<br>Faktur Penjualan |
| **payment_terms** | `suppliers`<br>`customers`<br>`purchase_orders`<br>`sales_orders` | `payment_term_id` | Pemasok<br>Pelanggan<br>Pesanan Pembelian<br>Pesanan Penjualan |

---

## 4. Hirarki Kategori Produk (Pencegahan Siklus & Batas Kedalaman)

Modul `product_categories` mengimplementasikan validasi pohon hirarki yang ketat pada `ProductCategoryService`:

1. **Pencegahan Self-Parenting**: Kategori dilarang memilih dirinya sendiri sebagai parent (`parent_id !== id`).
2. **Pencegahan Siklus Relasi (Cycle Prevention)**: Parent yang dipilih dilarang merupakan salah satu dari keturunan (*descendants*) kategori yang sedang diperbarui:
   ```php
   if (in_array((int) $parentId, $this->getDescendantIds($category->id), true)) {
       throw new BusinessRuleException('Kategori tidak dapat memiliki parent dari turunannya sendiri.', 422);
   }
   ```
3. **Batas Kedalaman Maksimum ($\le 5$ Tingkat)**: Kedalaman hierarki dihitung secara rekursif saat store dan update. Jika kedalaman melebihi 5 level, sistem menolak operasi dengan `BusinessRuleException` HTTP status 422.

---

## 5. Keamanan Float & Penanganan Aritmetika Desimal

Sesuai aturan baku arsitektur Fase 4:
- Seluruh kolom uang (`cost_price`, `selling_price`, `credit_limit`) disimpan sebagai `DECIMAL(15, 2)` pada database dan diperlakukan sebagai `string` di backend PHP.
- Seluruh kolom kuantitas (`min_stock`) disimpan sebagai `DECIMAL(12, 4)`.
- Kolom tarif persentase (`rate`) disimpan sebagai `DECIMAL(5, 2)` dengan CHECK constraint `rate >= 0 AND rate <= 100`.
- **Dilarang keras** mengonversi input atau atribut ke `float` atau `double` di controller, service, maupun resource.
- Komponen frontend Vue memanfaatkan formatter aman (`Rupiah.vue`, `Quantity.vue`, `PercentDisplay.vue`) yang memformat angka via pemisahan string (*string splitting*) tanpa `parseFloat()`.

---

## 6. Pola Halaman Frontend & Design System UI

Setiap entitas master data dilengkapi 4 komponen halaman Vue di bawah `resources/js/Pages/MasterData/<Module>/`:
1. `Index.vue`: Daftar tabel data dengan fitur pencarian, sortir dinamis, filter status, paginasi, dan tombol hapus dengan konfirmasi modal aman.
2. `Create.vue`: Halaman pembuatan rekaman baru dengan breadcrumb dan form submission.
3. `Edit.vue`: Halaman pengubahan rekaman dengan pre-fill data.
4. `Form.vue`: Komponen formulir terisolasi dan reusable yang mengikat props form Inertia `useForm()`.

### Desain Antarmuka:
- Memanfaatkan token CSS variables tema terang dan gelap.
- Komponen UI sepenuhnya mandiri dari Design System (`Button`, `TextInput`, `Select`, `Switch`, `FormField`, `DataTable`, `Pagination`, `FilterBar`, `ConfirmDialog`, `Badge`, `StatusBadge`).
- **Aksesibilitas Teruji**: Formulir dan tabel telah diaudit lolos otomatis menggunakan axe-core dengan 0 pelanggaran *critical* atau *serious*.

---

## 7. Rute & Otorisasi RBAC

Semua 48 rute master data didaftarkan di `routes/web.php` di bawah grup prefix `/master` dengan nama `master.*`. Setiap endpoint dilindungi dua lapis keamanan:
1. Middleware `auth` (beserta `AuthenticateSession`, `EnforceSessionTimeouts`, dan `EnsureUserIsActive`).
2. Middleware otorisasi granular `can:<module>.<action>` yang dipetakan langsung ke tabel `permissions`:
   - `units.view`, `units.create`, `units.update`, `units.delete`
   - `product_categories.view`, `product_categories.create`, `product_categories.update`, `product_categories.delete`
   - `products.view`, `products.create`, `products.update`, `products.delete`
   - `warehouses.view`, `warehouses.create`, `warehouses.update`, `warehouses.delete`
   - `suppliers.view`, `suppliers.create`, `suppliers.update`, `suppliers.delete`
   - `customers.view`, `customers.create`, `customers.update`, `customers.delete`
   - `tax_rates.view`, `tax_rates.create`, `tax_rates.update`, `tax_rates.delete`
   - `payment_terms.view`, `payment_terms.create`, `payment_terms.update`, `payment_terms.delete`

---

## 8. Data Demo & Seeder Pengujian (`DemoMasterDataSeeder`)

Tersedia seeder data realistis dan idempoten untuk demonstrasi dan verifikasi lokal:
```bash
make seed-demo
```
- Mengisi data realistis untuk 8 entitas master data (satuan barang perdagangan, struktur kategori bertingkat, gudang utama/transit, syarat pembayaran termin komersial, pemasok, pelanggan, dan katalog produk makanan/minuman).
- **Fail-safe Production**: Memiliki proteksi `app()->isProduction()` yang langsung melempar `RuntimeException` jika dijalankan di lingkungan produksi.
