# Modul Akuntansi: Bagan Akun & Jurnal

Dokumen ini menjelaskan mesin jurnal Fase 8B: pemetaan akun, jurnal otomatis, pembalik, backfill, dan batasannya. Faktur, pembayaran, dan laporan keuangan dibangun pada fase berikutnya.

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

## 9. Batasan yang Disengaja

Tanpa tutup buku/periode terkunci, tanpa nota retur/kredit, tanpa uang muka/kelebihan bayar, tanpa multi-mata-uang, tanpa penyusutan/aset tetap, tanpa gabungan faktur lintas SO/PO, kredit pelanggan hanya dicek saat menyetujui SO, dan laporan umur piutang/utang hanya posisi hari ini.

**Selisih valuasi yang diketahui:** void penyesuaian stok yang menambah barang masih mengeluarkan stok pada biaya rata-rata saat itu, sedangkan jurnal pembaliknya memakai nilai asal, sehingga stok dan akun 1301 bisa berselisih.
