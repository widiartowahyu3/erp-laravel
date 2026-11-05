# Modul Akuntansi: Bagan Akun & Jurnal

Dokumen ini menjelaskan mesin jurnal: pemetaan akun, jurnal otomatis, pembalik, backfill, dan batasannya. Faktur penjualan tersedia sejak Fase 9 ([rincian](SALES.md)), penerimaan pembayaran sejak Fase 10, serta faktur pembelian ([rincian](PURCHASING.md)) dan pengeluaran ke pemasok sejak Fase 11. Laporan keuangan dibangun pada fase berikutnya.

---

## 1. Prinsip

- Pembukuan berpasangan dalam satu mata uang (IDR), uang `decimal(18,2)`, dihitung dengan `App\Support\Decimal` tanpa float.
- Jurnal otomatis ditulis **di dalam transaksi posting dokumen sumber**. Gagal jurnal berarti seluruh posting gagal (*fail-closed*).
- Jurnal berstatus `posted` tidak diubah dan tidak dihapus. Koreksi hanya lewat jurnal pembalik.

## 2. Pemetaan Akun

Pemetaan ada di `config/accounting.php` dan dibaca lewat `AccountResolver`. Akun harus ada, aktif, dan dapat diposting; bila tidak, posting ditolak dengan HTTP 422 yang menyebut kode akunnya.

| Kunci | Kode | Akun |
|---|---|---|
| `inventory` | 1301 | Persediaan Barang Dagang |
| `receivable` | 1201 | Piutang Usaha |
| `payable` | 2101 | Utang Usaha |
| `grir` | 2103 | Barang Diterima Belum Ditagih (GR/IR) |
| `vat_output` | 2201 | PPN Keluaran |
| `vat_input` | 1401 | PPN Masukan |
| `sales` | 4101 | Penjualan |
| `sales_discount` | 4201 | Potongan Penjualan |
| `cogs` | 5101 | Harga Pokok Penjualan |
| `inventory_adjustment` | 5201 | Selisih Persediaan |

Akun kas/bank untuk pembayaran: 1101 dan 1102. Seeder `ChartOfAccountSeeder` berisi 21 akun: 6 header dan 15 detail.

## 3. Jurnal Otomatis

| Kode | Pemicu | Debit | Kredit | `source_type` |
|---|---|---|---|---|
| JT-GR | Posting penerimaan barang | `inventory` = Σ qty × `unit_cost` | `grir` | `goods_receipt` |
| JT-DO | Posting surat jalan | `cogs` = Σ qty × `unit_cost` snapshot | `inventory` | `delivery_order` |
| JT-SA | Posting penyesuaian stok | Selisih lebih: `inventory`; selisih kurang: `inventory_adjustment` | Selisih lebih: `inventory_adjustment`; selisih kurang: `inventory` | `stock_adjustment` |
| JT-SI | Posting faktur penjualan | `receivable` = total; `sales_discount` = jumlah diskon | `sales` = subtotal; `vat_output` = jumlah pajak | `sales_invoice` |
| JT-PAYR | Posting penerimaan pembayaran | Akun kas/bank pada pembayaran = jumlah | `receivable` = jumlah | `payment` |
| JT-PI | Posting faktur pembelian | `grir` = nilai GR yang diakui; `vat_input` = jumlah pajak; `inventory_adjustment` = selisih bila nilai bersih lebih besar | `payable` = total; `inventory_adjustment` = selisih bila nilai bersih lebih kecil | `purchase_invoice` |
| JT-PAYD | Posting pembayaran ke pemasok | `payable` = jumlah | Akun kas/bank pada pembayaran = jumlah | `payment` |

- `journal_date` memakai tanggal dokumen.
- Baris bernilai nol tidak dibuat. Bila semua nol, tidak ada jurnal dan peristiwa `journal.skipped` dicatat di audit.
- **Idempotensi:** satu `(source_type, source_id)` hanya punya satu jurnal asal dan paling banyak satu pembalik. Pemanggilan ganda mengembalikan jurnal yang sudah ada.

## 4. Pembatalan & Koreksi

- Void dokumen yang sudah diposting membuat jurnal pembalik otomatis: baris jurnal asal disalin dengan debit dan kredit ditukar (tidak dihitung ulang), `reversal_of_id` menunjuk jurnal asal, `journal_date` memakai tanggal void, dan jurnal asal tetap `posted`.
- Dokumen yang diposting sebelum modul ini ada dan belum di-backfill tidak punya jurnal. Void-nya tidak membuat pembalik dan dicatat di audit sebagai `journal.reversal_skipped`.
- Jurnal otomatis tidak boleh dibalik atau dibatalkan langsung (HTTP 409). Batalkan dokumen sumbernya.
- **Void penerimaan barang** mengeluarkan stok pada biaya GR tersebut, sehingga nilai stok tetap cocok dengan akun persediaan. Void ditolak (HTTP 409) bila sisa nilai persediaan akan negatif.

## 5. Jurnal Manual

Siklus `draft` → `posted`, atau `draft` → `void` dengan alasan wajib. Syarat: minimal 2 baris, Σ debit = Σ kredit > 0, akun aktif dan dapat diposting, tiap baris hanya debit atau kredit. Jurnal manual `posted` dapat dibalik sekali dengan alasan 1-200 karakter. Nomor memakai format `JV/YYYYMM/NNNNN`.

**Draf juga harus seimbang**, karena `chk_journals_balance` di database mewajibkan `total_debit = total_credit` untuk semua status.

## 6. Urutan Penguncian Global

Wajib sama di semua layanan untuk mencegah deadlock:

1. Baris `customers`/`suppliers` (hanya pengecekan kredit saat menyetujui SO)
2. SO / PO
3. DO / GR / penyesuaian stok (header, `id` menaik)
4. Baris item SO / PO (`id` menaik)
5. Baris item DO / GR (`id` menaik)
6. `payments` (header)
7. `sales_invoices` / `purchase_invoices` (header, `id` menaik)
8. `stock_balances` (`product_id` menaik, di dalam `StockService`)
9. `document_sequences` (di dalam `DocumentNumberService`)
10. Penulisan `journals` dan `journal_lines`

Posting faktur penjualan mengikuti urutan itu: SO, DO, baris SO, baris DO, faktur, nomor dokumen, jurnal. Karena semua penulisan untuk satu SO mengambil kunci SO lebih dulu, faktur dan pembatalan DO pada SO yang sama berjalan bergantian. Pembatalan faktur hanya mengunci faktur, dan persetujuan SO mengunci pelanggan lalu SO.

Posting faktur pembelian mengikuti pola yang sama dengan PO dan GR, dan pembatalannya mengunci PO lalu faktur karena bergantung pada penagihan lain di PO itu. Posting dan pembatalan pembayaran mengunci pembayaran, lalu fakturnya menurut `id` menaik, lalu menulis jurnal. Pembatalan faktur tidak bisa menyalip pembayaran yang sedang diposting: bila pembayaran lebih dulu memegang kunci faktur, pembatalan melihat `paid_amount` sudah terisi dan ditolak.

## 7. Backfill

Dokumen yang diposting sebelum modul ini ada dibuatkan jurnalnya dengan:

```bash
make artisan cmd='erp:journals:backfill'
```

Tanpa opsi, perintah hanya simulasi (*dry-run*). Tambahkan `--execute` untuk menulis:

```bash
make artisan cmd='erp:journals:backfill --execute'
```

Perintah ini idempoten, memproses GR, DO, dan penyesuaian stok berstatus `posted` yang belum punya jurnal (urut `posted_at`, `id`), melewati dokumen `void`, dan mencatat satu entri audit `journal.backfill`.

## 8. Rute & Izin

| Rute | Izin |
|---|---|
| `/accounting/journals` | `journals.view`, `.create`, `.update`, `.post`, `.reverse`, `.void` |
| `/accounting/accounts` | `chart_of_accounts.view`, `.create`, `.update`, `.delete` |

`journals.reverse` adalah izin baru, dipegang `super_admin` dan `finance`. Pasang ke database yang sudah ada dengan `make artisan cmd='erp:permissions:sync'`.

### Aturan Bagan Akun

- Induk harus akun header dan tidak boleh membentuk siklus. Akun yang punya anak harus berupa header.
- `type` dan `normal_balance` bebas dipasangkan, karena akun kontra seperti Potongan Penjualan memang sah.
- Akun yang sudah dipakai baris jurnal tidak boleh berubah `code`, `type`, `normal_balance`, dan `is_postable`.
- Akun yang dipetakan di `config/accounting.php` tidak boleh dinonaktifkan, diganti kodenya, diubah menjadi header, atau dihapus.
- Hapus (*soft delete*) hanya untuk akun tanpa baris jurnal dan tanpa anak.

### Rute pembayaran

| Rute | Izin |
|---|---|
| `GET /accounting/payments`, `GET /accounting/payments/{id}` | `payments.view` |
| `GET /accounting/payments/create`, `POST /accounting/payments` | `payments.create` |
| `GET /accounting/payments/{id}/edit`, `PUT /accounting/payments/{id}` | `payments.update` |
| `POST /accounting/payments/{id}/post` | `payments.post` |
| `POST /accounting/payments/{id}/void` | `payments.void` |

Formulir mendapat faktur terbuka milik pelanggan terpilih dengan memuat ulang rute `create` atau `edit` memakai `customer_id` sebagai kunjungan parsial Inertia, sehingga izinnya sama dengan rute itu.

## 9. Antarmuka

- **Bagan Akun:** daftar pohon dengan indentasi per level, filter tipe dan status, serta kolom terkunci yang tampil nonaktif beserta keterangannya.
- **Jurnal:** filter tanggal, status, dan sumber. Jurnal pembalik diberi lencana "Pembalik" dan jurnal yang sudah dibalik diberi lencana "Sudah dibalik".
- **Editor baris:** mengisi debit mengosongkan kredit pada baris yang sama, dan sebaliknya. Indikator total dan selisih memakai `Support/money.js`. Tombol "Simpan dan Posting" hanya aktif saat seimbang. Tombol "Simpan Draf" tetap bisa ditekan, tetapi server menolak draf yang tidak seimbang.
- **Jurnal terkait:** halaman detail GR, DO, penyesuaian stok, faktur penjualan, dan pembayaran menampilkan nomor dan status jurnalnya bagi pengguna yang punya izin `journals.view`.
- **Pembayaran:** daftar dengan filter status, pelanggan, metode, dan tanggal. Formulir menampilkan faktur terbuka pelanggan terpilih, tombol **Alokasikan otomatis**, serta indikator "Dialokasikan" dan "Sisa belum dialokasikan". Detail menampilkan alokasi sebagai tautan ke fakturnya, dan dialog pembatalan menjelaskan akibatnya pada faktur.
- **Dari faktur:** halaman detail faktur berstatus `posted` atau `partially_paid` menampilkan tombol **Terima Pembayaran** bagi pemegang `payments.create`, dan daftar alokasinya menaut ke pembayaran.
- **Pengeluaran:** halaman pembayaran punya tab Penerimaan dan Pengeluaran, dan tombol **Bayar Pemasok** membuka formulir untuk pemasok dengan faktur pembelian terbukanya. Alokasinya menaut ke faktur pembelian.

## 10. Penerimaan Pembayaran

Alur: draf, alokasi ke faktur, posting. Satu pembayaran boleh melunasi beberapa faktur milik pelanggan yang sama.

- **Akun:** hanya kode pada `cash_bank` di `config/accounting.php` (1101, 1102) yang aktif dan dapat diposting.
- **Draf:** tidak mereservasi sisa faktur. Boleh disimpan dengan alokasi kurang dari jumlah pembayaran, bahkan tanpa alokasi, tetapi tidak boleh melebihinya.
- **Posting:** semua aturan diperiksa ulang di bawah kunci. Jumlah alokasi harus sama persis dengan jumlah pembayaran, karena uang muka dan kelebihan bayar tidak didukung. Tiap alokasi harus lebih dari 0, tidak melebihi sisa faktur, dan fakturnya harus berstatus `posted` atau `partially_paid` milik pelanggan yang sama. Bila dua draf bersaing, posting yang kedua ditolak (422 pada `allocations`) tanpa mengubah apa pun.
- **Efek pada faktur:** `paid_amount` bertambah, dan status menjadi `partially_paid` atau `paid`. Logikanya hanya ada di `InvoiceSettlement`.
- **Pembatalan:** alasan wajib, 1 sampai 255 karakter. Draf dibatalkan tanpa efek. Pembayaran diposting mengembalikan `paid_amount`, menghitung ulang status faktur (`paid` ke `partially_paid`, atau ke `posted` bila nol), dan membuat jurnal pembalik.
- **Faktur yang sudah dibayar** tidak dapat dibatalkan sebelum pembayarannya dibatalkan.
- **Batas kredit:** piutang beredar otomatis turun saat pembayaran diposting dan naik lagi saat dibatalkan.

### Pembayaran ke pemasok

Aturannya sama, memakai pemasok dan faktur pembelian: draf, alokasi, posting, dan pembatalan pada rute dan izin `payments.*` yang sama. `payment_type` menentukan pihak dan jenis faktur yang boleh dialokasikan, sehingga penerimaan tidak dapat dialokasikan ke faktur pembelian dan sebaliknya. Faktur pembelian yang sudah dibayar tidak dapat dibatalkan sebelum pembayarannya dibatalkan.

### Akun Barang Diterima Belum Ditagih (2103)

Posting GR mengkreditkan 2103 dan posting faktur pembelian mendebitnya. Saldo kredit yang tersisa adalah barang yang sudah diterima tetapi belum ditagih pemasok, dan menjadi tepat nol setelah semua kuantitas GR ditagih.

## 11. Pelaporan

Laporan keuangan hanya menghitung jurnal berstatus `posted`, menurut `journal_date`. Jurnal asal yang sudah dibalik tetap berstatus `posted` dan ikut dihitung bersama pembaliknya, sehingga nilainya saling menghapus. Saldo dibaca pada sisi normal akun, jadi akun kontra seperti 4201 tampil negatif di kelompok pendapatan.

Tanpa tutup buku, laba rugi tidak pernah dipindahkan ke akun ekuitas. Neraca menghitung laba berjalan dari seluruh akun pendapatan dan beban sampai tanggal posisi, termasuk tahun-tahun sebelumnya. Definisi tiap laporan ada di [`REPORTS.md`](REPORTS.md).

Skenario ujung ke ujung di `tests/Feature/Accounting/EndToEndAccountingTest.php` memeriksa seluruh rantai dokumen: jurnal seimbang, persamaan akuntansi, saldo 2103, rekonsiliasi persediaan, umur piutang, dan laba bersih.

## 12. Batasan yang Disengaja

Tanpa tutup buku/periode terkunci, tanpa nota retur/kredit, tanpa uang muka/kelebihan bayar, tanpa multi-mata-uang, tanpa penyusutan/aset tetap, tanpa gabungan faktur lintas SO/PO, kredit pelanggan hanya dicek saat menyetujui SO, dan laporan umur piutang/utang hanya posisi hari ini.

**Selisih valuasi yang diketahui:** void penyesuaian stok yang menambah barang masih mengeluarkan stok pada biaya rata-rata saat itu, sedangkan jurnal pembaliknya memakai nilai asal, sehingga stok dan akun 1301 bisa berselisih.
