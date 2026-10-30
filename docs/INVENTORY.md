# Arsitektur & Spesifikasi Modul Inventori (Inventory)

Dokumen ini mendefinisikan arsitektur sistem, aturan integritas data, spesifikasi layanan, mekanisme penguncian (*concurrency locking*), dan siklus hidup dokumen untuk modul inventori (Fase 7) ERP Distribusi/Trading.

---

## 1. Arsitektur Data Inventori

Modul inventori memisahkan secara tegas antara *snapshot saldo* (pembacaan cepat) dan *buku besar mutasi* (catatan audit permanen):

```
+-------------------------------------------------------------------------+
|                               TRANSAKSI                                 |
|  (Goods Receipt / Sales Delivery / Stock Adjustment / Void Reversal)   |
+------------------------------------+------------------------------------+
                                     |
                                     v
                       +-----------------------------+
                       |    StockService (Atomic)    |
                       +--------------+--------------+
                                      |
         +----------------------------+----------------------------+
         | (Pessimistic Row Lock: product_id ASC)                  |
         v                                                         v
+-------------------------------+                       +-------------------------------+
|        stock_balances         |                       |        stock_movements        |
|-------------------------------|                       |-------------------------------|
| Snapshot Saldo & Biaya        |                       | Buku Besar Mutasi Stok        |
| - product_id (FK)             |                       | - movement_date (datetime)    |
| - warehouse_id (FK)           |                       | - product_id, warehouse_id    |
| - quantity (decimal 18,4 >= 0)|                       | - movement_type (enum)        |
| - avg_cost (decimal 18,4 >= 0)|                       | - quantity (+masuk / -keluar) |
| UNIQUE(product_id, warehouse) |                       | - unit_cost, balance_after    |
| Constraint chk >= 0           |                       | - source_type, source_id      |
| Tanpa soft-delete             |                       | - APPEND-ONLY (Trigger Lock)  |
+-------------------------------+                       +-------------------------------+
```

### Karakteristik Tabel
1. **`stock_balances`**:
   - Snapshot saldo instan per produk per gudang.
   - Dilindungi constraint database `chk_stock_balances_quantity` (`quantity >= 0`) dan `chk_stock_balances_avg_cost` (`avg_cost >= 0`).
   - Tidak menggunakan soft-delete atau audit user (tabel operasional murni).
2. **`stock_movements`**:
   - Buku besar (*general inventory ledger*) bersifat **APPEND-ONLY**.
   - Dilindungi trigger database `tr_stock_movements_before_update` dan `tr_stock_movements_before_delete` yang secara keras menolak manipulasi atau penghapusan data mutasi (`stock_movements is append-only`).
   - Kolom `quantity` bertanda (+ untuk masuk, - untuk keluar) dengan constraint `chk_stock_movements_quantity` (`quantity <> 0`).
   - Menyimpan jejak saldo sesaat (`balance_after`) dan biaya rata-rata sesaat (`avg_cost_after`) untuk audit kartu stok.
3. **`stock_adjustments` & `stock_adjustment_items`**:
   - Dokumen penyesuaian stok fisik (*stock opname* atau koreksi kuantitas gudang).
   - Menyimpan `system_quantity`, `counted_quantity`, `difference_quantity`, dan `unit_cost`.
   - Menggunakan nomor dokumen otomatis bebas celah (`SA/YYYYMM/XXXXX`) melalui `DocumentNumberService`.

---

## 2. Kalkulasi Biaya Rata-Rata Bergerak (*Moving Average Cost*)

Sistem menghitung biaya perolehan persediaan menggunakan metode Moving Average Cost secara matematis murni menggunakan library `App\Support\Decimal` berbasis `bcmath` (tanpa floating point).

### A. Mutasi Masuk (`receipt`, `adjustment_in`)
Nilai biaya rata-rata baru dihitung secara proporsional terhadap saldo lama:

$$\text{avg\_cost}_{\text{baru}} = \frac{(\text{qty}_{\text{lama}} \times \text{cost}_{\text{lama}}) + (\text{qty}_{\text{masuk}} \times \text{cost}_{\text{masuk}})}{\text{qty}_{\text{lama}} + \text{qty}_{\text{masuk}}}$$

- **Kondisi Khusus (Saldo Awal Kosong / Nol):** Bila $\text{qty}_{\text{lama}} \le 0$, maka $\text{avg\_cost}_{\text{baru}} = \text{cost}_{\text{masuk}}$.

### B. Mutasi Keluar (`delivery`, `adjustment_out`)
- Barang keluar dinilai sebesar `avg_cost` saldo saat ini:
  $$\text{unit\_cost}_{\text{mutasi}} = \text{avg\_cost}_{\text{saat\_ini}}$$
- Mutasi keluar **TIDAK MENGUBAH** nilai `avg_cost` persediaan tersisa.
- **Pemeriksaan Stok Negatif:** Bila $\text{qty}_{\text{keluar}} > \text{qty}_{\text{saldo}}$, sistem segera membatalkan transaksi dan melempar `InsufficientStockException` (HTTP 422).

---

## 3. Protokol Penguncian Konkurensi (*Concurrency Locking Order*)

Untuk mencegah kondisi balapan (*race condition*) dan kebuntuan (*deadlock*) pada transaksi paralel:
1. Setiap mutasi inventori wajib berada dalam database transaction aktif (`RequiresTransaction`).
2. Baris `stock_balances` dikunci menggunakan *pessimistic lock* (`lockForUpdate()`).
3. Bila sebuah transaksi memutasi banyak item produk sekaligus (mis. multi-item Goods Receipt atau Stock Adjustment), penguncian baris `stock_balances` **WAJIB DIURUTKAN SECARA DETERMINISTIK MENAIK BERDASARKAN `product_id ASC`**:
   ```php
   // Mengurutkan ID produk untuk mencegah circular lock wait (deadlock)
   $sortedProductIds = collect($items)->pluck('product_id')->unique()->sort()->values();
   foreach ($sortedProductIds as $productId) {
       StockBalance::query()
           ->where('product_id', $productId)
           ->where('warehouse_id', $warehouseId)
           ->lockForUpdate()
           ->first();
   }
   ```

---

## 4. API Layanan `StockService`

`App\Services\Inventory\StockService` menyediakan antarmuka terpadu untuk seluruh mutasi stok:

- `applyMovement(StockMovementType $type, int $productId, int $warehouseId, string $quantity, ?string $unitCost, CarbonInterface|string $date, string $sourceType, int $sourceId, int $userId): StockMovement`
  - Memverifikasi transaksi database aktif.
  - Mengunci baris saldo.
  - Memvalidasi kecukupan stok bila mutasi keluar.
  - Menghitung saldo baru dan biaya rata-rata baru via `MovingAverageCostCalculator`.
  - Mengupdate/membuat rekaman di `stock_balances`.
  - Menambahkan baris ke `stock_movements`.
- `getBalance(int $productId, int $warehouseId): ?StockBalance`
  - Mengambil snapshot saldo terkini.
- `getStockCardQuery(int $productId, int $warehouseId, ?string $dateFrom, ?string $dateTo)`
  - Menghasilkan query pergerakan kartu stok kronologis terurut tanggal beserta saldo awal periode.

---

## 5. Siklus Hidup Penyesuaian Stok (`stock_adjustments`)

```
   [ Buat Draf ]
         |
         v
     +-------+        Posting         +--------+
     | DRAFT | --------------------> | POSTED | (Mutasi Stok Aktif)
     +-------+                       +--------+
         |                                |
         | Batal                          | Batal (Void)
         v                                v
     +-------+                       +--------+
     | VOID  |                       |  VOID  | (Mutasi Pembalik Otomatis)
     +-------+                       +--------+
```

1. **Draf (`draft`)**:
   - Dokumen dapat diubah (`edit`) atau dihapus sebelum diposting.
   - Belum ada pergerakan stok di `stock_balances` maupun `stock_movements`.
2. **Posting (`posted`)**:
   - Draf diverifikasi dan diposting via `StockAdjustmentService::post()`.
   - Menghitung `difference_quantity = counted_quantity - system_quantity`.
   - Bila `diff > 0`: membuat mutasi `ADJUSTMENT_IN` dengan biaya perolehan `unit_cost`.
   - Bila `diff < 0`: membuat mutasi `ADJUSTMENT_OUT` dengan biaya perolehan `avg_cost` saldo saat ini.
   - Menandai dokumen sebagai `posted` lengkap dengan `posted_by` dan `posted_at`.
3. **Pembatalan (`void`)**:
   - Dilakukan via `StockAdjustmentService::void(reason)`.
   - Memerlukan input alasan pembatalan minimal 5 karakter (`void_reason`).
   - Bila dokumen yang dibatalkan sudah berstatus `posted`, sistem secara otomatis membuat **mutasi pembalik** (*compensating movement*):
     - `ADJUSTMENT_IN` dibalikkan dengan `ADJUSTMENT_OUT`.
     - `ADJUSTMENT_OUT` dibalikkan dengan `ADJUSTMENT_IN`.
   - Dokumen diberi status `void` dengan `voided_by`, `voided_at`, dan `void_reason`. Nomor dokumen tetap tersimpan di database untuk menjaga integritas nomor seri.

---

## 6. Hak Akses & Pembatasan Laju (Security Hardening)

- **Permission Matrix**:
  - `stock_balances.view`: Melihat daftar saldo stok.
  - `stock_movements.view`: Melihat riwayat mutasi stok dan buku kartu stok.
  - `stock_adjustments.view`: Melihat daftar & detail penyesuaian stok.
  - `stock_adjustments.create`: Membuat draf penyesuaian stok.
  - `stock_adjustments.update`: Mengubah draf penyesuaian stok.
  - `stock_adjustments.post`: Memposting penyesuaian stok ke inventori fisik.
  - `stock_adjustments.void`: Membatalkan dokumen penyesuaian stok.
- **Pemisahan Peran (*Separation of Duties*)**:
  - Peran `warehouse` memegang hak operasional inventori penuh (`stock_adjustments.*`, `stock_balances.view`, `stock_movements.view`).
  - Peran `purchasing` dan `admin` dibatasi hanya melihat saldo dan mutasi (`*.view`).
- **Rate Limiting**: Seluruh endpoint mutasi (`POST /inventory/stock-adjustments`, `PUT ...`, `POST .../post`, `POST .../void`) terikat middleware `throttle:erp-write` (maksimal 60 request per menit per user/IP).
