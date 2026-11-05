# Arsitektur & Spesifikasi Modul Penjualan (Sales)

Dokumen ini mendefinisikan alur penjualan barang, kalkulasi finansial pesanan penjualan (*Sales Order*), penerbitan dan pengiriman surat jalan (*Delivery Order*), mesin status dokumen (*document state machine*), protokol penguncian konkurensi, mekanika mutasi stok & biaya rata-rata bergerak (*moving average cost*), serta matriks keamanan rute untuk modul penjualan (Fase 8A) ERP Distribusi/Trading.

> [!NOTE] Catatan Ralat Arsitektur Frontend
> Composable `useActionGuard` hanya berfungsi memeriksa hak akses (RBAC) dan izin status dokumen secara deklaratif (*apa yang boleh dilakukan*).
> Pengaman klik ganda (*double-click / spamming*) dan status tombol sibuk (*busy state*) ditangani secara terpisah oleh composable `useSubmitLock` (*kapan aksi boleh dikirim*).

---

## 1. Alur Bisnis Penjualan (Sales to Delivery Lifecycle)

Alur bisnis penjualan dirancang mengikuti prinsip pemisahan wewenang (*separation of duties*) antara bagian penjualan (*sales*) dan operasional logistik gudang (*warehouse*):

```
+-----------------------------------------------------------------------------------+
| Bagian Penjualan (Sales)                                                          |
|                                                                                   |
|  1. Buat Pesanan Penjualan (SO Draf)                                              |
|  2. Persetujuan Pesanan Penjualan (SO Disetujui / Approved)                       |
+------------------------------------------+----------------------------------------+
                                           |
                                           v
+-----------------------------------------------------------------------------------+
| Bagian Logistik Gudang (Warehouse)                                                |
|                                                                                   |
|  3. Penerbitan Surat Jalan: Terbitkan Surat Jalan (DO Draf) dari SO Disetujui     |
|  4. Posting Pengiriman (DO Posted):                                               |
|     - Mutasi Keluar Inventori (StockService: DELIVERY, source: delivery_order)    |
|     - Snapshot Biaya Pokok (unit_cost) Tersimpan di delivery_order_items          |
|     - Saldo Fisik Gudang Berkurang                                                |
|     - Status & Kemajuan SO Terupdate Otomatis (Partially Delivered / Delivered)   |
+------------------------------------------+----------------------------------------+
                                           |
                    (Opsional jika batal)  v
+-----------------------------------------------------------------------------------+
| Pembatalan Surat Jalan (DO Void):                                                 |
|  - Mutasi Masuk Pemulihan Stok (StockService: RECEIPT, source: delivery_order_void)|
|  - Pemulihan Biaya menggunakan Snapshot unit_cost persis saat pengiriman           |
|  - Kemajuan & Status SO Dihitung Mundur Otomatis (Rollback Status SO)              |
+-----------------------------------------------------------------------------------+
```

---

## 2. Kalkulasi Nilai Pesanan Penjualan (`SalesOrderCalculator`)

Perhitungan finansial pesanan penjualan diatur secara terpusat melalui `App\Support\Sales\SalesOrderCalculator` yang mendelegasikan komputasi matematika ke fondasi bersama `App\Support\Documents\LineTotalsCalculator` berbasis `bcmath` dengan skala 2 desimal (`SCALE_MONEY`):

### A. Kalkulasi Baris Item
Untuk setiap item pesanan penjualan:
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
- Jika $\text{discount\_amount} > \text{line\_gross}$, pembuatan atau pembaruan SO ditolak dengan `BusinessRuleException` status HTTP 422 (`items.<index>.discount_amount`).
- Diskon 100% ($\text{discount\_amount} = \text{line\_gross}$) diizinkan dan menghasilkan $\text{line\_net} = 0$, $\text{line\_total} = 0$, dengan header tetap konsisten secara matematis.

---

## 3. Mesin Status Dokumen (*Document State Machine*)

Status dokumen dikendalikan secara ketat oleh `DocumentStateMachine` serta batasan integritas basis data (`CHECK constraint`).

### A. Siklus Status Pesanan Penjualan (`SalesOrderStatus`)
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
         /   ^  \
        /    |   \ void (bila belum ada DO aktif)
       /     |    v
      |      |  +------+
      |      |  | VOID |
      |      |  +------+
      v      | (rollback jika DO dibatalkan)
   +---------------------+      all delivered      +-----------+
   | PARTIALLY_DELIVERED | ----------------------> | DELIVERED |
   +---------------------+                         +-----------+
      ^       |    \                                     |
      |       |     \ close                              | close
      |       |      v                                   v
      |       |   +--------+                          +--------+
      |       +-> | CLOSED | (Terminal)               | CLOSED | (Terminal)
      |           +--------+                          +--------+
      |               |
      +---------------+ (rollback jika DO dibatalkan)
```

Aturan Transisi Status SO:
1. `draft` $\rightarrow$ `approved`, `void`.
2. `approved` $\rightarrow$ `partially_delivered`, `delivered`, `void` (void hanya jika tidak ada DO berstatus draft atau posted).
3. `partially_delivered` $\rightarrow$ `delivered`, `closed`, `approved` (rollback jika DO dibatalkan).
4. `delivered` $\rightarrow$ `closed`, `partially_delivered` (rollback jika DO dibatalkan).
5. `closed` $\rightarrow$ `partially_delivered`, `delivered` (hanya rollback dari DO void).
6. `void` adalah status terminal (tidak dapat diubah kembali).

### B. Siklus Status Surat Jalan (`DeliveryOrderStatus`)
```
       +------------+
       |   DRAFT    |
       +------------+
         /        \
  post  /          \ void
       v            v
  +----------+   +------+
  |  POSTED  |   | VOID | (Terminal)
  +----------+   +------+
       |
       | void (dengan pemulihan stok)
       v
    +------+
    | VOID | (Terminal)
    +------+
```

Aturan Transisi Status DO:
1. `draft` $\rightarrow$ `posted`, `void`.
2. `posted` $\rightarrow$ `void` (dengan mutasi pemulihan stok dan kalkulasi mundur status SO).
3. `void` adalah status terminal.

---

## 4. Mekanika Mutasi Stok & Biaya Inventori (S4, S5, S8)

### A. Draf Surat Jalan Tidak Memesan/Memotong Stok
- Pembuatan dan pembaruan DO berstatus `draft` **TIDAK** memotong saldo stok gudang dan **TIDAK** mencatat pergerakan stok (`stock_movements`).
- Saldo stok baru dipotong saat DO di-posting (`posted`).

### B. Posting Surat Jalan (`post`)
1. Memvalidasi ketersediaan stok fisik gudang secara deterministik:
   $$\text{quantity\_available} \ge \text{quantity\_to\_deliver}$$
   Jika stok tidak mencukupi, sistem melempar `InsufficientStockException` (HTTP 422) dan seluruh transaksi di-rollback secara utuh.
2. Memanggil `StockService::decrease` dengan tipe mutasi `StockMovementType::DELIVERY` dan sumber `delivery_order`.
3. Mengambil nilai biaya perolehan rata-rata bergerak (*moving average unit cost*) dari entitas `StockMovement` yang dihasilkan, dan menyimpannya ke dalam kolom `delivery_order_items.unit_cost` sebagai **snapshot permanen**.
4. Memperbarui `delivered_quantity` pada `sales_order_items` dan memperbarui status pesanan penjualan melalui `SalesOrderProgress::determineStatus`.
5. Pemeriksaan status pesanan penjualan saat posting DO meloloskan status `approved`, `partially_delivered`, dan `delivered`, di mana pesanan yang telah terpenuhi penuh (`delivered`) akan ditolak secara deterministik oleh validasi sisa kuantitas (*remaining quantity check*) meskipun pesan galat status masih menyebut "disetujui atau terkirim sebagian".

### C. Pembatalan Surat Jalan Terposting (`void`)
1. Memulihkan kuantitas stok gudang dengan memanggil `StockService::increase` dengan tipe mutasi `StockMovementType::RECEIPT` dan sumber `delivery_order_void`.
2. Biaya satuan pemulihan stok menggunakan **snapshot biaya satuan** yang tersimpan di `delivery_order_items.unit_cost` saat pengiriman awal (bukan biaya rata-rata berjalan saat ini), untuk menjamin integritas nilai persediaan secara akuntansi.
3. Mengurangi `delivered_quantity` pada `sales_order_items`.
4. Memperbarui status pesanan penjualan melalui `SalesOrderProgress::determineStatus` (dapat melakukan rollback dari `delivered` ke `partially_delivered` atau ke `approved`).

5. **Ditolak bila sudah ditagih:** surat jalan yang barisnya dirujuk faktur berstatus selain `void`, termasuk draf, tidak dapat dibatalkan (409 pada `status`, pesan menyebut nomor fakturnya). Batalkan fakturnya terlebih dahulu.

---

## 5. Protokol Konkurensi & Penguncian Transaksi (S7)

Untuk mencegah kebuntuan (*deadlock*) pada lingkungan transaksi konkurensi tinggi, semua pembaruan basis data pesanan penjualan dan surat jalan mengikuti urutan penguncian baris (*pessimistic locking*) yang ketat:

$$\text{SalesOrder} \longrightarrow \text{DeliveryOrder} \longrightarrow \text{SalesOrderItems (id ASC)} \longrightarrow \text{StockBalances (product\_id ASC)}$$

1. **Header SalesOrder (`lockForUpdate`):** Mengunci pesanan penjualan induk.
2. **Header DeliveryOrder (`lockForUpdate`):** Mengunci surat jalan yang bersangkutan.
3. **Baris SalesOrderItem (`orderBy('id', 'asc')->lockForUpdate()`):** Mengunci seluruh baris item pesanan penjualan terurut menaik berdasarkan primary key ID.
4. **Saldo Stok Produk (`orderBy('product_id', 'asc')` via `StockService`):** Mengunci baris saldo stok gudang terurut menaik berdasarkan ID produk untuk mencegah deadlock antar armada pengiriman.

---

## 6. Matriks Keamanan & Hak Akses (RBAC & Route Guards)

### A. Hak Akses Spasial
| Fitur / Tindakan | Izin (*Permission*) | Sales | Warehouse | Finance | Admin |
| :--- | :--- | :---: | :---: | :---: | :---: |
| Lihat Daftar & Detail SO | `sales_orders.view` | ✓ | ✓ | ✓ | ✓ |
| Buat Draf SO | `sales_orders.create` | ✓ | ✗ | ✗ | ✗ |
| Ubah Draf SO | `sales_orders.update` | ✓ | ✗ | ✗ | ✗ |
| Setujui SO | `sales_orders.approve` | ✓ | ✗ | ✗ | ✗ |
| Batalkan SO | `sales_orders.void` | ✓ | ✗ | ✗ | ✗ |
| Lihat Daftar & Detail DO | `delivery_orders.view` | ✓ | ✓ | ✓ | ✓ |
| Buat Draf DO | `delivery_orders.create` | ✗ | ✓ | ✗ | ✗ |
| Ubah Draf DO | `delivery_orders.update` | ✗ | ✓ | ✗ | ✗ |
| Posting Pengiriman DO | `delivery_orders.post` | ✗ | ✓ | ✗ | ✗ |
| Batalkan DO | `delivery_orders.void` | ✗ | ✓ | ✗ | ✗ |

### B. Pengamanan Rute Web
1. **Autentikasi & Otorisasi:** Seluruh 17 rute `/sales/*` dilindungi middleware `auth` dan pemanggilan `can:<permission>` deklaratif pada definisi rute di `src/routes/web.php`.
2. **Pencegahan Flooding (Rate Limiting):** Seluruh mutasi data (POST, PUT) diproteksi middleware `throttle:erp-write`.
3. **Audit Trail:** Header dokumen mencatat `created_by`, `updated_by`, serta aktor aksi status (`approved_by`, `posted_by`, `voided_by`) via trait `RecordsAudit`.
4. **Penomoran Dokumen Gapless:** Dikelola oleh `DocumentNumberService` di dalam transaksi database serial dengan format `SO/YYYYMM/XXXXX` dan `DO/YYYYMM/XXXXX`.

---

## 7. Keputusan Desain Arsitektur

Berikut adalah keputusan arsitektural dan batasan desain yang ditetapkan pada modul penjualan:

1. **`movement_date` Mutasi Stok Adalah Waktu Posting (`now()`):**
   - Tanggal pergerakan stok (`movement_date`) pada mutasi pengiriman DO dicatat menggunakan timestamp eksekusi posting aktual (`now()`), bukan `delivery_date` dokumen. Hal ini konsisten dengan pencatatan penerimaan barang (*Goods Receipt*) di Fase 7 guna memastikan kartu stok dan urutan kronologis buku besar inventori mencerminkan kejadian fisik secara riil.
2. **Draf DO Tidak Memesan/Mengunci Stok:**
   - Surat jalan berstatus `draft` tidak memesan (*reserve*) maupun memotong saldo fisik persediaan di gudang. Mekanisme ini selaras dengan modul pembelian (*purchasing*), di mana draf dokumen tidak mengunci persediaan. Pemotongan saldo fisik dan validasi kecukupan stok (`InsufficientStockException`) baru dieksekusi secara atomik saat posting.
3. **Pengecekan Limit Kredit Pelanggan Ditunda ke Fase 8B:**
   - Validasi batas kredit (*credit limit*) dan piutang jatuh tempo pelanggan sengaja ditunda ke Fase 8B saat faktur penjualan (*Sales Invoice*) dan buku besar piutang (*Accounts Receivable*) dibangun. Pada Fase 8A, alur difokuskan pada integritas operasional pemesanan (SO) dan pemenuhan pengiriman fisik (DO).

---

## 8. Antarmuka Pengguna & Integrasi Frontend (Fase 8A Bagian 2)

### A. Halaman Modul Penjualan
1. **Pesanan Penjualan (`Pages/Sales/SalesOrders/`)**:
   - `Index.vue`: Tabel data pesanan penjualan dengan filter pencarian, pelanggan, gudang, status, rentang tanggal, pagination, dan status badge.
   - `Form.vue`: Formulir pembuatan dan pengubahan pesanan penjualan dengan pengisian otomatis termin pembayaran saat pelanggan dipilih, pengisian harga jual standar produk, serta kalkulasi nilai kotor, diskon hingga 100%, PPN, dan grand total secara realtime berbasis BigInt tanpa floating point.
   - `Create.vue` & `Edit.vue`: Pembungkus halaman formulir dengan breadcrumbs dan judul dinamis.
   - `Show.vue`: Rincian lengkap pesanan penjualan yang menampilkan kolom kuantitas dipesan, kuantitas terkirim, dan sisa pesanan, riwayat dokumen surat jalan terkait, serta tombol aksi kontekstual (*Approve*, *Void*, *Close*, *Create DO*).
2. **Surat Jalan / Pengiriman (`Pages/Sales/DeliveryOrders/`)**:
   - `Index.vue`: Tabel data surat jalan dengan filter pencarian, pelanggan, gudang, status, rentang tanggal, dan tombol pembuatan yang mengarahkan ke daftar SO disetujui.
   - `Create.vue`: Formulir pembuatan surat jalan dari pesanan penjualan via rute `/sales/delivery-orders/create?sales_order_id={id}` dengan pemuatan otomatis baris pesanan yang belum terkirim penuh (*eligible items*).
   - `Edit.vue`: Formulir pengubahan draf surat jalan.
   - `Show.vue`: Rincian surat jalan dengan penyembunyian informasi HPP (`unit_cost`) pada status draf/batal dan menampilkan HPP hanya saat dokumen berstatus `posted`. Dilengkapi tombol *Posting DO* (dengan peringatan pemotongan stok gudang) dan *Batalkan DO* (dengan modal alasan pembatalan).

### B. Batasan Ruang Lingkup Saat Ini
Fitur berikut belum tersedia dan dialokasikan untuk fase berikutnya:
1. **Penerimaan Pembayaran Piutang (*Customer Payments*)**: Fase 10. Tanpa uang muka dan tanpa kelebihan bayar.
2. **Laporan Analitis Penjualan (*Sales Reports*)**: Fase 12.

---

## 9. Faktur Penjualan (Fase 9)

Rute `/sales/invoices`. Satu faktur memuat satu pelanggan dan satu SO, dan barisnya berasal dari baris DO berstatus `posted` milik SO itu, boleh dari beberapa DO. Gabungan lintas SO tidak didukung.

### A. Alur
```text
SO disetujui -> DO diposting -> Faktur draf -> Faktur diposting -> Dibayar (Fase 10)
                                     |               |
                                     v               v
                                 Dibatalkan      Dibatalkan (bila belum ada pembayaran, dengan jurnal pembalik)
```

SO harus berstatus `approved`, `partially_delivered`, `delivered`, atau `closed`, dan pelanggan harus aktif saat faktur dibuat maupun saat diposting.

### B. Sisa yang dapat ditagih
`sisa = kuantitas baris DO - jumlah kuantitas pada faktur diposting (posted, partially_paid, paid) yang merujuk baris itu`.

Draf **tidak** mereservasi kuantitas: dua draf boleh mengincar sisa yang sama. Saat posting, sisa dan semua nilai dihitung ulang di bawah kunci, sehingga posting kedua yang melampaui sisa ditolak (422 pada `items`) tanpa mengubah apa pun. Faktur yang dibatalkan membebaskan kuantitasnya.

Pengguna hanya menentukan baris, kuantitas, tanggal, jatuh tempo, dan catatan. Harga, diskon, dan pajak selalu dihitung server dari baris SO.

### C. Alokasi diskon dan pajak (`SalesInvoiceCalculator`)
- Bruto baris: `kuantitas x harga satuan`.
- Diskon baris: proporsional `diskon SO x kuantitas / kuantitas SO`, dibulatkan 2 desimal. Penagihan yang menggenapi kuantitas baris SO mengambil **sisa diskon**, sehingga jumlah diskon sama persis dengan diskon SO. Contoh diskon 100 untuk 3 unit: 33,33 + 33,33 + 33,34.
- Pajak per baris: `(bruto - diskon) x tarif / 100`, dengan tarif dari baris SO.
- Header: `subtotal = jumlah bruto`, `total = subtotal - diskon + pajak`.
- Penagihan bertahap dapat berselisih beberapa sen terhadap bruto atau pajak SO karena pembulatan per baris. Selisih ini diterima.

### D. Jatuh tempo
`tanggal faktur + hari termin`, dengan termin dari SO, lalu dari pelanggan, lalu 0 hari. Pada draf boleh diubah selama tidak sebelum tanggal faktur. Tanggal faktur tidak boleh di masa depan.

### E. Pembatalan
Alasan wajib, 1 sampai 255 karakter. Draf dibatalkan tanpa jurnal. Faktur diposting hanya dapat dibatalkan bila `paid_amount = 0` (selain itu 409 pada `status`, "batalkan pembayaran terlebih dahulu") dan menghasilkan jurnal pembalik.

---

## 10. Batas Kredit Pelanggan (Fase 9)

Diperiksa hanya saat SO disetujui, di bawah kunci baris pelanggan:

`eksposur = jumlah (total - paid_amount) faktur posted/partially_paid milik pelanggan + total SO yang disetujui`

- `credit_limit = 0` berarti tanpa batas. Kolom ini tidak boleh NULL dan default-nya 0.
- Eksposur **tepat sama** dengan batas diizinkan. Bila melebihi, persetujuan ditolak (422 pada `customer_id`) dengan pesan berisi batas, piutang beredar, dan nilai pesanan, dan SO tetap draf.
- Tidak ada izin override: naikkan batasnya di master pelanggan.

**Celah yang disengaja:** SO terbuka lain dan DO terkirim yang belum difakturkan tidak dihitung. Beberapa SO yang disetujui berurutan masing-masing bisa lolos walaupun jumlahnya melewati batas, sampai fakturnya diposting.
