# Strategi Pengujian

## 1. Lapisan tes

| Lapisan | Alat | Letak | Yang dibuktikan |
|---|---|---|---|
| Arsitektur | Pest Arch | `src/tests/Arch` | Larangan fungsi berbahaya dan pola kode |
| Backend | Pest | `src/tests/Feature`, `src/tests/Unit` | Aturan bisnis, izin, validasi, dan constraint database, pada MariaDB sungguhan |
| Frontend | Vitest dan axe-core | `src/resources/js/test` | Komponen asli, kontrak props, dan aksesibilitas |
| Statis | PHPStan, Pint, ESLint | `make check` | Tipe dan gaya kode |
| Hardening | Bash | `scripts/verify-hardening.sh` | Container, header, hak database, dan rute pada stack yang berjalan |
| Rahasia | Gitleaks | Hook dan CI | Tidak ada rahasia di riwayat |

## 2. Prinsip

- Tes backend memakai MariaDB yang sama jenisnya dengan produksi, dengan transaksi yang dibatalkan di akhir tiap tes.
- Angka uang diuji persis sebagai string, misalnya `'1400.00'`.
- Tes frontend hanya men-stub `AppLayout`, jadi kontrak komponen UI ikut teruji.
- Aturan `heading-order` axe-core aktif pada halaman baru.
- Skenario `src/tests/Feature/Accounting/EndToEndAccountingTest.php` menjalankan seluruh siklus dan memeriksa keseimbangan buku pada tiap tahap.

## 3. Menjalankan

```bash
make check
make test
make test-js
make verify
```

Perintah berat sebaiknya dijalankan satu per satu. `make verify` butuh container yang sedang berjalan.

## 4. Membaca gerbang

Satu fase dianggap siap ditinjau bila semua perintah berikut berhasil pada commit yang sama, tanpa ada berkas yang berubah selagi berjalan:

1. `make check`, sekali.
2. `make test`, tiga kali berturut-turut untuk menangkap tes yang tidak stabil.
3. `npm run build`, `npm run lint`, dan `npm audit`.
4. `make verify`.
5. `composer audit` dan Gitleaks.

Keberhasilan di lokal belum membuktikan CI. Status akhirnya ada di tab Actions GitHub setelah commit di-push.

## 5. Angka terakhir

Dari log pada 2026-11-05 di commit `e842bcc`:

| Rangkaian | Hasil |
|---|---|
| Backend | 1304 tes lulus, 11844 penegasan |
| Penjaga integritas akhir (`ZGuardIntegrityTest`) | 1 tes lulus, 3 penegasan |
| Frontend | 47 berkas, 631 tes lulus |

Angka ini akan bertambah di fase berikutnya. Sumber yang berlaku tetap keluaran perintah di atas.
