# Panduan Operasional & Pemeliharaan Sistem (Fase 7.5)

Dokumen ini mendefinisikan prosedur operasional pemeliharaan database, strategi pencadangan (*backup*), validasi pemulihan (*restore-check*), target ketersediaan (RPO/RTO), serta protokol keamanan data pada sistem ERP Distribusi / Trading.

---

## 1. Prosedur Pencadangan (*Database Backup*)

Sistem menyediakan skrip otomasi pencadangan database MariaDB 12.3 terisolasi di dalam container:

### A. Eksekusi Backup
Pencadangan database dapat dijalankan melalui perintah `make`:
```bash
make backup
```
Atau memanggil skrip langsung:
```bash
bash scripts/db-backup.sh
```

### B. Mekanisme & Keamanan Teknis
1. **Hak Akses & Isolasi Kredensial**:
   - Kata sandi pengguna `root` MariaDB dibaca langsung dari berkas `/run/secrets/db_root_password` di dalam container database (pola yang sama dengan `make db`).
   - Kata sandi **TIDAK PERNAH** diekspos sebagai argumen baris perintah (mencegah kebocoran di process table `ps`) dan tidak pernah dicetak ke layar/log.
2. **Flag Integritas `mariadb-dump`**:
   - `--single-transaction`: Menjamin konsistensi pembacaan data transaksional InnoDB tanpa mengunci tabel (*non-blocking reads*).
   - `--routines`: Mencadangkan stored procedures dan fungsi.
   - `--triggers`: Mencadangkan seluruh trigger integritas database.
   - `--events`: Mencadangkan scheduled events MariaDB.
3. **Penyimpanan & Izin Berkas**:
   - Berkas dump langsung dikompresi ke format gzip (`.sql.gz`) dengan penamaan berbasis UTC timestamp: `backups/erp_trading_YYYYMMDD_HHMMSSZ.sql.gz`.
   - Direktori `backups/` dikunci dengan hak akses ketat `700` (`rwx------`).
   - Setiap berkas backup otomatis diberi hak akses `600` (`rw-------`).
   - Direktori `/backups/` didaftarkan di `.gitignore` untuk mencegah berkas backup tidak sengaja ter-commit ke repositori git.

---

## 2. Validasi Pemulihan (*Restore-Check*)

Backup tanpa pengujian berkala adalah ilusi keamanan. Sistem menyediakan alat uji pemulihan otomatis yang memvalidasi integritas data:

### A. Eksekusi Restore-Check
```bash
make restore-check
```
Atau memanggil skrip langsung:
```bash
bash scripts/db-restore-check.sh
```

### B. Tahapan Verifikasi
1. **Database Uji Sementara**:
   - Skrip membuat database sementara dengan nama baku yang terisolasi: `erp_trading_restore_check`.
   - Menolak secara ketat nama database lain demi mencegah penimpaan data produksi.
2. **Restorasi Dump Terbaru**:
   - Mengambil arsip backup `.sql.gz` terbaru dari direktori `backups/` dan memulihkannya ke dalam `erp_trading_restore_check`.
3. **Pemeriksaan Jumlah Baris & Checksum**:
   - Menghitung jumlah baris (`SELECT COUNT(*)`) untuk seluruh tabel antara database asal (`erp_trading`) dan database pemulihan (`erp_trading_restore_check`).
   - Melakukan verifikasi biner baris per tabel via `CHECKSUM TABLE`.
   - Tabel volatil yang bersifat dinamis/transient (`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`) dilewati secara terukur dari verifikasi checksum.
4. **Verifikasi Trigger Append-Only**:
   - Memastikan 4 trigger append-only kritis pulih sempurna di skema:
     - `tr_audit_logs_before_update`
     - `tr_audit_logs_before_delete`
     - `tr_stock_movements_before_update`
     - `tr_stock_movements_before_delete`
5. **Pembersihan Database Sementara**:
   - Setelah seluruh pengujian berhasil (exit code 0), database sementara `erp_trading_restore_check` segera dihapus (`DROP DATABASE`).

---

## 3. Sasaran RPO dan RTO (Target Realistis & Baseline Terukur)

### A. Matriks Target Pemulihan

| Metrik | Target | Keterangan & Asumsi |
|---|---|---|
| **RPO** (*Recovery Point Objective*) | **≤ 24 Jam** (Dump Harian) <br> *≤ 5 Menit* (dengan Binlog) | Dengan dump logis harian, data maksimal yang hilang adalah transaksi sejak backup terakhir. Jika point-in-time recovery diaktifkan via binary log MariaDB, RPO dapat ditekan hingga menit terakhir. |
| **RTO** (*Recovery Time Objective*) | **≤ 15 Menit** *(Target Teoretis - Belum Diuji pada Ukuran Tersebut)* | Target teoretis untuk transfer arsip lokal, pembuatan skema, serta dekompresi/impor dump pada volume data hingga 5 GB. **Catatan:** Belum diuji pada skala data 5 GB. |

### B. Hasil Pengukuran Baseline Aktual (`make restore-check`)
Hasil pengukuran riil pada lingkungan pengujian saat ini (diukur via `time make restore-check`, tercatat pada `review/f76-restore-time.txt`):
- **Ukuran Berkas Dump**: ~60 KB (terkompresi `.sql.gz`) / ~430 KB (teks SQL mentah).
- **Waktu Eksekusi Penuh**: **10,38 detik** (`real 0m10.388s`, `user 0m0.418s`, `sys 0m0.384s`).
- **Cakupan Pengujian**: Penciptaan basis data sementara, pemulihan dump lengkap, verifikasi jumlah baris dan `CHECKSUM TABLE` pada 38 tabel non-volatile, validasi keberadaan 4 trigger append-only, dan pembersihan database sementara.

---

## 4. Jadwal & Retensi Pencadangan yang Disarankan

Untuk operasional produksi, disarankan memasang jadwal berkala (misal via Cron pada sistem host):

```cron
# Contoh cron host untuk pencadangan setiap pukul 02:00 UTC
0 2 * * * cd /path/to/erp-trading && make backup >> /var/log/erp-backup.log 2>&1
# Menjalankan verifikasi pemulihan setiap hari Minggu pukul 03:00 UTC
0 3 * * 0 cd /path/to/erp-trading && make restore-check >> /var/log/erp-restore-check.log 2>&1
```

### Pola Retensi (Grandfather-Father-Son / GFS)
1. **Harian (Daily)**: Disimpan selama 7 hari terakhir.
2. **Mingguan (Weekly)**: Disimpan selama 4 minggu terakhir.
3. **Bulanan (Monthly)**: Disimpan selama 12 bulan terakhir.
4. **Arsip Tahunan**: Disimpan selama 5-7 tahun sesuai kepatuhan hukum pajak/akuntansi Indonesia.

---

## 5. Retensi Log Audit

Log audit yang berumur lebih dari 7 hari dihapus otomatis. Ini keputusan pemilik pada 4 November 2026 untuk semua jenis event, termasuk perubahan transaksi dan hak akses.

- **Jadwal**: `erp:audit:prune` berjalan setiap pukul 01.00 WIB di container `scheduler` (`php artisan schedule:work`), sebelum jadwal backup pukul 02.00 UTC yang disarankan di atas.
- **Cara kerja**: baris dihapus per 1.000. Setiap kelompok dicatat sebagai event `audit_log.pruned` dalam transaksi yang sama, sehingga bila pencatatan gagal, penghapusan kelompok itu dibatalkan.
- **Batas di database**: trigger `tr_audit_logs_before_delete` menolak penghapusan baris yang berumur 7 hari atau kurang. Mengubah masa simpan berarti membuat migrasi trigger baru dan mengubah `AuditRetentionService`.
- **Riwayat lama**: baris yang sudah terhapus hanya dapat dipulihkan dari backup. Masa simpan backup di bagian 4 menjadi satu-satunya arsip jejak audit yang lebih tua dari 7 hari, jadi pastikan arsip itu benar-benar dijalankan.
- **Restore-check**: `make restore-check` membandingkan isi `audit_logs` dengan database aktif, sehingga jalankan segera setelah `make backup`. Penghapusan atau penulisan log di antara keduanya menimbulkan ketidakcocokan checksum.
- **Pemeriksaan**:

```bash
docker compose exec scheduler php artisan schedule:list
```

```bash
docker compose logs --tail=20 scheduler
```

---

## 6. Peringatan Keamanan & Batasan Operasional

> [!CAUTION] Perlindungan Kerahasiaan Data
> Berkas dump database memuat informasi bisnis sensitif, data pelanggan, pemasok, transaksi, dan jejak audit. Dilarang keras mengunggah atau membagikan berkas dump mentah tanpa enkripsi.
> Saat memindahkan berkas backup ke offsite backup / object storage (S3/GCS), arsip **wajib dienkripsi** secara asimetris (misal menggunakan GPG atau `age`).

> [!IMPORTANT] Hak Akses DEFINER Trigger
> Trigger append-only sistem dikompilasi dengan definer `DEFINER=\`erp_migrator\`@\`%\``. Akun yang menjalankan proses restorasi wajib memiliki hak administratif (`SUPER` atau `TRIGGER` privilege) yang setara dengan `root` agar deklarasi trigger dan view dapat diterapkan tanpa galat hak akses.
