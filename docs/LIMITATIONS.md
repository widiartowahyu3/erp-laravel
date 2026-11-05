# Batasan Sistem

Dokumen ini mencatat apa yang sengaja belum ada, supaya tidak dianggap sudah tersedia. Sebagian adalah batasan rancangan, sebagian lagi pekerjaan yang belum dilakukan.

## 1. Akuntansi

- **Tanpa tutup buku atau periode terkunci.** Transaksi tetap bisa dicatat pada tanggal lampau. Laporan periode yang sudah lewat bisa berubah bila ada dokumen bertanggal lampau.
- **Laba tidak dipindahkan ke ekuitas.** Neraca menampilkan seluruh laba sebagai laba berjalan kumulatif.
- **Tanpa nota retur atau nota kredit.** Koreksi dilakukan dengan membatalkan dokumen.
- **Tanpa uang muka dan kelebihan bayar.** Pembayaran harus habis teralokasi ke faktur.
- **Tanpa multi-mata-uang.** Semua transaksi dalam Rupiah.
- **Tanpa penyusutan dan aset tetap.**
- **Satu faktur untuk satu SO atau PO.** Faktur gabungan lintas pesanan belum didukung.
- **Satu akun untuk tiap sisi PPN.** Belum ada pelaporan pajak.

## 2. Persediaan

- **Selisih valuasi pada void penyesuaian stok yang menambah barang.** Stok keluar pada biaya rata-rata saat itu, sedangkan jurnal pembaliknya memakai nilai asal. Laporan rekonsiliasi menampilkan selisihnya.
- **Pembulatan biaya rata-rata.** Biaya rata-rata disimpan empat desimal, jadi rekonsiliasi bisa berselisih beberapa sen setelah pembelian dengan harga berbeda.
- **Satu satuan dasar per produk.** Belum ada konversi satuan.
- **Tanpa dokumen transfer antargudang.** Perpindahan stok dilakukan dengan dua penyesuaian.

## 3. Penjualan dan Pembelian

- **Batas kredit hanya dicek saat menyetujui SO.**
- **Nomor faktur pemasok tetap terpakai walau fakturnya batal.** Keunikan berlaku untuk semua status.
- **Faktur pembelian yang digenapi faktur lain harus dibatalkan setelah faktur penggenapnya.**

## 4. Laporan

- **Umur piutang dan utang hanya untuk hari ini.** `paid_amount` hanya menyimpan posisi terakhir, jadi umur per tanggal lampau tidak bisa dibentuk ulang.
- **Rekonsiliasi persediaan hanya untuk saat ini.** Saldo stok tidak punya riwayat per tanggal di laporan ini.
- **Ekspor CSV dibatasi 50.000 baris.**
- **Tidak ada ekspor Excel atau PDF.** PDF dibuat dari dialog cetak peramban.
- **Bukti pembayaran belum punya tampilan cetak.**
- **Laporan arus kas belum ada.**

## 5. Administrasi dan Keamanan

- **Pendaftaran pengguna hanya lewat admin.** Tidak ada pendaftaran mandiri.
- **Tanpa 2FA dan tanpa lupa kata sandi lewat email.**
- **Tanpa wajib ganti kata sandi saat login pertama.**
- **Plafon izin admin.** Admin bukan Super Administrator tidak bisa memberi izin yang tidak ia miliki, sehingga admin bawaan tidak bisa mendaftarkan staf operasional.
- **Tanpa pemisahan pembuat dan penyetuju.** Pengguna dengan kedua izin bisa membuat dan menyetujui dokumen yang sama.
- **HPP terlihat oleh semua yang boleh melihat saldo stok.** Belum ada izin khusus untuk biaya.
- **Log audit dihapus setelah 7 hari.** Riwayat yang lebih tua hanya ada di cadangan.

## 6. Operasi

- **Cadangan belum otomatis.** Jadwal cron baru berupa saran di [`OPERATIONS.md`](OPERATIONS.md).
- **Deploy ke server masih manual** lewat `scripts/deploy.sh`.
