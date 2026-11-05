# Laporan

Semua laporan ada di bawah `/reports/*`, hanya memakai `GET`, dan tidak menulis data apa pun. Kuerinya berupa agregat SQL di `App\Services\Reports`, dan angka uang berupa string desimal tanpa `float`.

## 1. Daftar laporan dan izin

| Laporan | Rute | Izin | Dasar waktu |
|---|---|---|---|
| Neraca saldo | `/reports/trial-balance` | `reports.view_finance` | Periode |
| Buku besar | `/reports/general-ledger` | `reports.view_finance` | Periode, wajib pilih akun |
| Laba rugi | `/reports/income-statement` | `reports.view_finance` | Periode |
| Neraca | `/reports/balance-sheet` | `reports.view_finance` | Tanggal posisi `as_of` |
| Umur piutang | `/reports/receivable-aging` | `reports.view_finance` | Selalu hari ini |
| Umur utang | `/reports/payable-aging` | `reports.view_finance` | Selalu hari ini |
| Kartu piutang | `/reports/customer-statement` | `reports.view_finance` | Periode, wajib pilih pelanggan |
| Rekonsiliasi persediaan | `/reports/inventory-reconciliation` | `reports.view_finance` | Posisi saat ini |
| Ringkasan penjualan | `/reports/sales-summary` | `reports.view_sales` | Periode |
| Ringkasan pembelian | `/reports/purchasing-summary` | `reports.view_purchasing` | Periode |
| Ringkasan persediaan | `/reports/inventory-summary` | `reports.view_inventory` | Posisi saat ini |

Periode wajib berisi tanggal awal dan akhir, dengan rentang paling lama 5 tahun. Halaman yang dibuka tanpa tanggal memakai awal bulan berjalan sampai hari ini. Tanggal posisi kosong berarti hari ini menurut WIB.

## 2. Laporan keuangan

Keempat laporan ini hanya membaca jurnal `posted`. Jurnal draf dan jurnal batal tidak dihitung. Jurnal pembalik dihitung sebagai jurnal biasa.

- **Neraca saldo**: per akun rinci yang punya aktivitas sampai akhir periode. Saldo awal berasal dari jurnal sebelum tanggal awal, lalu mutasi debit dan kredit periode, lalu saldo akhir. Saldo dibaca pada sisi normal akun, sehingga bisa negatif. Nilai `balanced` membandingkan total debit dan kredit, baik saldo awal maupun mutasi.
- **Buku besar**: baris urut `journal_date`, `journal_id`, `line_no`, dengan saldo berjalan yang berlanjut antarhalaman. Saldo akhir mencakup seluruh halaman. Akun induk ditolak dengan 422.
- **Laba rugi**: pendapatan adalah kredit dikurangi debit akun bertipe `revenue`, sehingga potongan 4201 tampil negatif. Akun beban berkode awal `5` masuk Beban Pokok, sisanya masuk Beban Operasional. Laba kotor = pendapatan − beban pokok. Laba bersih = laba kotor − beban operasional.
- **Neraca**: aset, liabilitas, dan ekuitas sampai tanggal posisi. Karena belum ada tutup buku, baris laba berjalan menjumlahkan seluruh pendapatan dikurangi beban sampai tanggal itu, termasuk tahun-tahun sebelumnya. `balanced` berarti aset sama dengan liabilitas ditambah ekuitas.

## 3. Umur piutang dan utang

Hanya faktur `posted` dan `partially_paid` dengan sisa di atas nol. Sisa = `total` − `paid_amount`. Hari lewat dihitung dari `due_date` terhadap tanggal hari ini menurut aplikasi.

| Kolom | Hari lewat jatuh tempo |
|---|---|
| Belum jatuh tempo | 0 atau kurang, termasuk jatuh tempo hari ini |
| 1-30 | 1 sampai 30 |
| 31-60 | 31 sampai 60 |
| 61-90 | 61 sampai 90 |
| > 90 | 91 atau lebih |

Tanpa jurnal manual ke akun kontrol, total umur piutang sama dengan saldo akun 1201 dan total umur utang sama dengan saldo akun 2101. Jurnal manual ke akun itu membuat keduanya berbeda.

Umur hanya tersedia untuk hari ini karena `paid_amount` hanya menyimpan posisi terakhir. Rincian per faktur ikut dikirim dan bisa dibuka per pelanggan atau pemasok.

## 4. Kartu piutang

Berisi faktur `posted`, `partially_paid`, dan `paid` pada tanggal faktur, serta alokasi pembayaran `posted` pada tanggal pembayaran. Satu pembayaran yang membayar beberapa faktur tampil sebagai beberapa baris. Urutannya tanggal, lalu faktur sebelum pembayaran. Saldo akhir yang mencakup seluruh riwayat sama dengan sisa tagihan pelanggan.

## 5. Rekonsiliasi persediaan

Nilai stok = jumlah per baris `stock_balances` dari `quantity × avg_cost` yang dibulatkan ke dua desimal, dikelompokkan per gudang. Nilai ini dibandingkan dengan saldo akun 1301 dari seluruh jurnal `posted`. Selisih = nilai stok − saldo akun, ditampilkan apa adanya.

Sebab selisih yang diketahui:
- Pembulatan per baris saldo stok.
- Void penyesuaian stok yang menambah barang, karena stok keluar pada rata-rata saat itu sedangkan jurnal pembalik memakai nilai asal.
- Jurnal manual ke akun 1301.

## 6. Ringkasan operasional

- **Penjualan dan pembelian**: jumlah dan nilai pesanan yang bukan draf atau batal menurut tanggal pesanan, jumlah surat jalan atau penerimaan barang `posted`, jumlah dan nilai faktur `posted`, `partially_paid`, dan `paid`. Daftarnya berisi 10 pelanggan atau pemasok dengan nilai faktur terbesar.
- **Persediaan**: stok per gudang dan produk, bisa disaring per gudang dan hanya yang di bawah minimum. Stok minimum dibandingkan per gudang, sama seperti daftar stok. Laporan ini sengaja tidak memuat biaya, karena peran gudang membacanya. Produk yang belum pernah punya saldo stok tidak muncul.

## 7. Ekspor CSV

Tambahkan `?export=csv` pada rute laporan. Izinnya sama dengan halaman laporan.

- Berkas UTF-8 dengan BOM dan pemisah koma, dikirim secara streaming.
- Sel teks yang diawali `=`, `+`, `-`, `@`, tab, atau CR diberi awalan tanda petik tunggal agar tidak dijalankan sebagai formula. Kolom angka tidak diubah.
- Batasnya 50.000 baris. Ekspor yang melebihi batas ditolak dengan 422 dan tidak dipotong diam-diam.
- Buku besar dan kartu piutang diawali baris saldo awal. Laba rugi dan neraca diratakan menjadi bagian, kode, nama, dan jumlah, termasuk baris total.

## 8. Ekspor daftar

Tombol Ekspor CSV juga ada di 13 daftar: produk, pelanggan, pemasok, saldo stok, mutasi stok, pesanan pembelian, penerimaan barang, faktur pembelian, pesanan penjualan, surat jalan, faktur penjualan, pembayaran, dan jurnal.

- Rutenya sama dengan daftar, ditambah `?export=csv`, jadi izinnya sama dengan melihat daftar itu.
- Isinya semua baris yang cocok dengan pencarian, filter, dan urutan yang sedang aktif, bukan hanya satu halaman.
- Kolomnya tidak lebih dari yang sudah terlihat di daftar.
- Format, penjaga formula, dan batas 50.000 baris sama dengan ekspor laporan.

## 9. Cetak

Halaman laporan punya tombol Cetak. Saat dicetak, sidebar, filter, dan tombol disembunyikan, dan tabel berlanjut ke halaman berikutnya tanpa terpotong.

Dokumen berikut punya tampilan cetak di rute `.../{id}/print`, dengan izin yang sama dengan melihat dokumennya:

| Dokumen | Isi uang | Tanda tangan |
|---|---|---|
| Pesanan pembelian | Harga, diskon, pajak, total | Penyetuju |
| Penerimaan barang | Tanpa uang | Pengirim, penerima |
| Faktur pembelian | Harga, diskon, pajak, total, sisa tagihan | Tidak ada |
| Pesanan penjualan | Harga, diskon, pajak, total | Penyetuju, pelanggan |
| Surat jalan | Tanpa uang | Gudang, pengemudi, penerima |
| Faktur penjualan | Harga, diskon, pajak, total | Tidak ada |
| Penyesuaian stok | Tanpa uang | Penghitung, penyetuju |
| Jurnal | Debit dan kredit | Pembuat, pemeriksa |

Penerimaan barang, surat jalan, dan penyesuaian stok tidak mengirim harga atau biaya sama sekali ke halaman, karena kertasnya berpindah ke pengemudi, pemasok, atau pelanggan. Dokumen draf dan batal diberi cap statusnya agar tidak terbaca sebagai dokumen sah. Bukti pembayaran belum punya tampilan cetak.
