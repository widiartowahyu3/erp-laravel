# Arsitektur & Spesifikasi Modul Pembelian (Purchasing)

Dokumen ini mendefinisikan alur pengadaan barang, kalkulasi finansial pesanan pembelian, mesin status dokumen (*document state machine*), protokol penguncian konkurensi penerimaan barang, dan matriks keamanan untuk modul pembelian (Fase 7) ERP Distribusi/Trading.

> [!NOTE] Catatan Ralat Arsitektur Frontend
> Composable `useActionGuard` hanya berfungsi memeriksa hak akses (RBAC) dan izin status dokumen secara deklaratif (*apa yang boleh dilakukan*).
> Pengaman klik ganda (*double-click / spamming*) dan status tombol sibuk (*busy state*) ditangani secara terpisah oleh composable `useSubmitLock` (*kapan aksi boleh dikirim*).

---

## 1. Alur Pengadaan Barang Dagang (Procurement Lifecycle)

Alur bisnis pengadaan dirancang mengikuti prinsip pemisahan wewenang (*separation of duties*) antara bagian pembelian (*purchasing*) dan operasional logistik gudang (*warehouse*):

```
+-----------------------------------------------------------------------------------+
| Bagian Pembelian (Purchasing)                                                     |
|                                                                                   |
|  1. Buat Pesanan Pembelian (PO Draf)                                              |
|  2. Persetujuan Pesanan Pembelian (PO Approved)                                   |
+------------------------------------------+----------------------------------------+
                                           |
                                           v
+-----------------------------------------------------------------------------------+
| Bagian Logistik Gudang (Warehouse)                                                |
|                                                                                   |
|  3. Penerimaan Fisik Barang: Terbitkan Penerimaan Barang (GR Draf)                |
|  4. Posting GR:                                                                   |
|     - Mutasi Masuk Inventori (StockService: RECEIPT)                              |
|     - Saldo & Biaya Rata-rata Gudang Bertambah                                    |
|     - Progress PO Terupdate Otomatis (Partially Received / Received)              |
+-----------------------------------------------------------------------------------+
```

---

## 2. Kalkulasi Nilai Pesanan Pembelian (PurchaseOrderCalculator)

Perhitungan finansial pesanan pembelian diatur terpusat oleh `App\Support\Purchasing\PurchaseOrderCalculator` menggunakan `App\Support\Decimal` berbasis `bcmath` dengan skala 2 desimal (`SCALE_MONEY`):

### A. Kalkulasi Baris Item
Untuk setiap item pesanan:
1. **Harga Kotor (*Gross*):**
   $$\text{line\_gross} = \text{Decimal::mul}(\text{quantity}, \text{unit\_price}, 2)$$
2. **Diskon Baris (*Discount*):**
   $$\text{discount\_amount} = \text{Decimal::round}(\text{input\_discount}, 2)$$
3. **Harga Bersih (*Net*):**
   $$\text{line\_net} = \max(0, \text{Decimal::sub}(\text{line\_gross}, \text{discount\_amount}, 2))$$
4. **Pajak (*Tax*):**
   $$\text{tax\_amount} = \text{Decimal::mul}(\text{line\_net}, \text{tax\_rate} / 100, 2)$$
5. **Total Baris (*Line Total*):**
   $$\text{line\_total} = \text{Decimal::add}(\text{line\_net}, \text{tax\_amount}, 2)$$

### B. Kalkulasi Header Dokumen
1. $\text{subtotal} = \sum \text{line\_gross}$
2. $\text{discount\_amount} = \sum \text{discount\_amount}$
3. $\text{tax\_amount} = \sum \text{tax\_amount}$
4. $\text{total} = \text{subtotal} - \text{discount\_amount} + \text{tax\_amount} = \sum \text{line\_total}$

### C. Aturan Validasi Diskon Baris
Diskon baris dibatasi maksimal sebesar nilai kotor baris ($\text{discount\_amount} \le \text{line\_gross}$).
- Jika $\text{discount\_amount} > \text{line\_gross}$, pembuatan atau pembaruan PO ditolak dengan `BusinessRuleException` status HTTP 422 (`items.<index>.discount_amount`).
- Diskon 100% ($\text{discount\_amount} = \text{line\_gross}$) diizinkan dan menghasilkan $\text{line\_net} = 0$, $\text{line\_total} = 0$, dengan header tetap konsisten secara matematis.

### D. Biaya Perolehan Persediaan dari Penerimaan Barang (GR)
Biaya satuan persediaan pada Goods Receipt dihitung berdasarkan harga bersih setelah diskon tanpa memperhitungkan pajak:
$$\text{unit\_cost}_{\text{GR}} = \frac{\text{line\_gross} - \text{discount\_amount}}{\text{quantity}} = \frac{\text{line\_net}}{\text{quantity}}$$
- Pajak masukan (PPN) tidak dikapitalisasi ke dalam biaya persediaan karena dapat dikreditkan secara fiskal (bukan beban perolehan persediaan dagang).
- Nilai ini dievaluasi ulang saat posting GR untuk menjamin mutasi stok dan biaya rata-rata bergerak (*moving average*) dihitung dari nilai perolehan bersih aktual.

---

## 3. Mesin Status Dokumen (*Document State Machine*)

Status dokumen dikendalikan secara ketat oleh `DocumentStateMachine` yang menjamin transisi status yang valid.

### A. Siklus Status Pesanan Pembelian (`PurchaseOrderStatus`)
```
             +------------+
             |   DRAFT    |
             +------------+
               /        \
   approve    /          \ void
             v            v
      +----------+     +------+
      | APPROVED |     | VOID | (Terminal)
      +----------+     +------+
        /   |  \
       /    |   \ void
      /     |    v
     |      |  +------+
     |      |  | VOID |
     |      |  +------+
     v      v
  +--------------------+      all received       +----------+
  | PARTIALLY_RECEIVED | ----------------------> | RECEIVED |
  +--------------------+                         +----------+
     ^       |    \                                  |
     |       |     \ close                           | close
     |       |      v                                v
     |       |   +--------+                      +--------+
     |       |   | CLOSED | (Terminal)           | CLOSED | (Terminal)
     |       |   +--------+                      +--------+
     |       |
     | void  \---------------------+
     | GR                          |
     \-----------------------+     | void GR
      (Rollback Transition)  |     | (Rollback Transition)
                             v     v
                         (Ke Status Sebelumnya)
```

#### Transisi Rollback Status PO
Saat sebuah dokumen Penerimaan Barang (`GoodsReceipt`) berstatus `posted` dibatalkan (`void`), kuantitas `received_quantity` pada item PO dikurangi kembali. Sistem secara otomatis mengevaluasi ulang progres PO melalui `PurchaseOrderProgress::statusFor()` dan mendukung transisi rollback:
- `RECEIVED` $\rightarrow$ `PARTIALLY_RECEIVED` (bila masih ada GR lain yang aktif)
- `RECEIVED` $\rightarrow$ `APPROVED` (bila seluruh penerimaan dibatalkan)
- `PARTIALLY_RECEIVED` $\rightarrow$ `APPROVED` (bila seluruh penerimaan dibatalkan)

### B. Siklus Status Penerimaan Barang (`GoodsReceiptStatus`)
```
     +-------+        post        +--------+
     | DRAFT | -----------------> | POSTED | (Stok Gudang Bertambah)
     +-------+                    +--------+
         |                             |
    void |                        void | (Reversal Stok & Rollback PO)
         v                             v
     +-------+                    +--------+
     | VOID  |                    |  VOID  | (Terminal)
     +-------+                    +--------+
```

### C. Aturan Ketergantungan Status PO & GR
1. **Pembuatan, Pengubahan, dan Posting GR:**
   - Goods Receipt hanya dapat dibuat (`create`), diubah (`update`), atau diposting (`post`) jika PO induk berstatus `APPROVED` atau `PARTIALLY_RECEIVED`.
   - Percobaan operasi GR pada PO yang berstatus `CLOSED` atau `VOID` ditolak dengan `BusinessRuleException` status HTTP 409 Conflict (`field: 'status'`).
2. **Pembatalan (*Void*) Goods Receipt:**
   - GR berstatus `DRAFT` dapat dibatalkan kapan saja tanpa memandang status PO induk.
   - GR berstatus `POSTED` **HANYA** dapat dibatalkan jika PO induk belum berstatus `CLOSED`. Jika PO sudah `CLOSED`, pembatalan ditolak dengan HTTP 409 Conflict ("Penerimaan barang pada pesanan pembelian yang sudah ditutup tidak dapat dibatalkan.").
   - Pembalikan mutasi stok saat void GR dicatat sebagai **`adjustment_out`** dengan `source_type = goods_receipt_void` (bukan `delivery`).
3. **Penutupan (*Close*) Purchase Order:**
   - PO hanya dapat ditutup (`close`) jika tidak ada dokumen Goods Receipt yang masih berstatus `DRAFT` pada PO tersebut.
   - Jika masih ada GR draf, penutupan PO ditolak dengan HTTP 409 Conflict ("Masih ada penerimaan barang berstatus draf. Posting atau batalkan terlebih dahulu.").
4. **Pembatalan (*Void*) Purchase Order:**
   - PO yang memiliki dokumen Goods Receipt aktif (berstatus selain `VOID`) tidak dapat dibatalkan langsung dan ditolak dengan HTTP 409 Conflict (`field: 'status'`).

---

## 4. Protokol Penguncian Konkurensi Penerimaan Barang

Saat memposting atau membatalkan penerimaan barang, beberapa tabel terkait diperbarui secara atomik. Untuk menghindari kondisi deadlock antar worker paralel, urutan penguncian (*locking order*) ditetapkan secara seragam:

1. **Lock Baris PO:**
   ```php
   $po = PurchaseOrder::query()->where('id', $poId)->lockForUpdate()->firstOrFail();
   ```
2. **Lock Baris GR:**
   ```php
   $receipt = GoodsReceipt::query()->where('id', $grId)->lockForUpdate()->firstOrFail();
   ```
3. **Lock Baris Item PO (Urut `id ASC`):**
   ```php
   $poItems = PurchaseOrderItem::query()
       ->where('purchase_order_id', $po->id)
       ->orderBy('id', 'asc')
       ->lockForUpdate()
       ->get()
       ->keyBy('id');
   ```
4. **Lock Saldo Stok di `StockService` (Urut `product_id ASC`):**
   ```php
   StockBalance::query()
       ->where('product_id', $productId)
       ->where('warehouse_id', $warehouseId)
       ->lockForUpdate()
       ->first();
   ```

Penerapan urutan kunci deterministik (PO $\rightarrow$ GR $\rightarrow$ PO Items ID $\rightarrow$ Stock Balances Product ID) menjamin sistem terbebas dari siklus tunggu deadlock (error 1213).

---

## 5. Hak Akses & Matriks Otorisasi (RBAC)

- **Permission Matrix**:
  - `purchase_orders.view`: Melihat daftar & detail pesanan pembelian.
  - `purchase_orders.create`: Membuat draf pesanan pembelian.
  - `purchase_orders.update`: Mengubah draf pesanan pembelian atau menutup PO (`close`).
  - `purchase_orders.approve`: Menyetujui pesanan pembelian untuk diproses gudang.
  - `purchase_orders.void`: Membatalkan pesanan pembelian.
  - `goods_receipts.view`: Melihat daftar & detail surat penerimaan barang.
  - `goods_receipts.create`: Membuat draf surat penerimaan barang.
  - `goods_receipts.update`: Mengubah draf surat penerimaan barang.
  - `goods_receipts.post`: Memposting penerimaan barang ke saldo inventori.
  - `goods_receipts.void`: Membatalkan penerimaan barang dan membalikkan stok.
- **Pemisahan Peran**:
  - Peran `purchasing`: Menguasai `purchase_orders.*` dan hanya memiliki hak intip `goods_receipts.view`.
  - Peran `warehouse`: Menguasai `goods_receipts.*` dan hanya memiliki hak intip `purchase_orders.view`.
  - Peran `admin`: Hanya memiliki hak intip `*.view`.
- **Rate Limiting**: Seluruh endpoint transaksi mutasi (`POST /purchasing/purchase-orders`, `PUT ...`, `POST .../approve`, `POST .../close`, `POST .../void`, `POST /purchasing/goods-receipts`, `PUT ...`, `POST .../post`, `POST .../void`) terikat middleware `throttle:erp-write`.
