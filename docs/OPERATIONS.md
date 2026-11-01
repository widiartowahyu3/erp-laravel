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

## 3. Sasaran RPO dan RTO (Target Realistis)

| Metrik | Target | Keterangan & Asumsi |
|---|---|---|
| **RPO** (*Recovery Point Objective*) | **≤ 24 Jam** (Dump Harian) <br> *≤ 5 Menit* (dengan Binlog) | Dengan dump logis harian, data maksimal yang hilang adalah transaksi sejak backup terakhir. Jika point-in-time recovery diaktifkan via binary log MariaDB, RPO dapat ditekan hingga menit terakhir. |
| **RTO** (*Recovery Time Objective*) | **≤ 15 Menit** | Waktu yang dibutuhkan untuk transfer berkas arsip lokal, pembuatan skema, dan eksekusi dekompresi/impor dump pada database seukuran hingga 5 GB. |

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

## 5. Peringatan Keamanan & Batasan Operasional

> [!CAUTION] Perlindungan Kerahasiaan Data
> Berkas dump database memuat informasi bisnis sensitif, data pelanggan, pemasok, transaksi, dan jejak audit. Dilarang keras mengunggah atau membagikan berkas dump mentah tanpa enkripsi.
> Saat memindahkan berkas backup ke offsite backup / object storage (S3/GCS), arsip **wajib dienkripsi** secara asimetris (misal menggunakan GPG atau `age`).

> [!IMPORTANT] Hak Akses DEFINER Trigger
> Trigger append-only sistem dikompilasi dengan definer `DEFINER=\`erp_migrator\`@\`%\``. Akun yang menjalankan proses restorasi wajib memiliki hak administratif (`SUPER` atau `TRIGGER` privilege) yang setara dengan `root` agar deklarasi trigger dan view dapat diterapkan tanpa galat hak akses.
