# Panduan Rilis & Deploy Production

Dokumen ini menjelaskan alur CI, cara merilis versi baru, dan cara menjalankan aplikasi di host Docker production. Belum ada server tetap, jadi deploy dijalankan manual dengan `scripts/deploy.sh`.

---

## 1. Alur CI/CD

| Pemicu | Workflow | Isi |
|---|---|---|
| Push ke `main`, pull request | `.github/workflows/ci.yml` | Pint, PHPStan, Pest, Vitest, ESLint, `composer validate`, `composer audit`, `npm audit` (semua dependensi di level high, dependensi runtime di level moderate), `make verify`, Gitleaks seluruh riwayat, lalu uji asap image production |
| Tag `vX.Y.Z` | `.github/workflows/release.yml` | Seluruh isi `ci.yml`, lalu mendorong image yang lolos uji asap ke GHCR dan membuat GitHub Release berisi digest image |

Tag `latest` sengaja tidak dipakai agar setiap deploy menunjuk versi yang jelas.

### Uji asap lokal
```bash
make prod-smoke
```
Skrip ini membangun image production dan menjalankannya pada project Docker terpisah (`erp-prod-smoke`) dengan database tmpfs dan port loopback, sehingga stack pengembangan tidak tersentuh. Yang diuji: deploy, migrasi, HTTPS, header keamanan, cookie secure, login menyeluruh, jalur sensitif, hardening container, backup, dan rollback.

### Pin image
`scripts/check-image-pins.sh` memastikan pin image yang sama dipakai di `compose.yaml`, `compose.prod.yaml`, `docker/php/Dockerfile`, dan `docker/node/Dockerfile`. Dependabot mengubah berkas satu per satu, jadi pull request pembaruan image bisa gagal di pemeriksaan ini sampai pin di berkas lain ikut diperbarui.

---

## 2. Merilis Versi Baru

```bash
git tag v1.0.0
git push origin v1.0.0
```

Image yang dihasilkan:
- `ghcr.io/widiartowahyu3/erp-laravel-php:v1.0.0`
- `ghcr.io/widiartowahyu3/erp-laravel-web:v1.0.0`

---

## 3. Menyiapkan Server (Sekali Saja)

Prasyarat: Linux dengan Docker Engine dan Compose v2.24 atau lebih baru, port 80 dan 443 terbuka, serta domain yang mengarah ke server.

1. **Ambil repositori** pada tag yang akan dirilis. Server hanya memakai `compose.prod.yaml`, `scripts/`, `docker/mariadb/`, dan berkas contoh lingkungan; kode aplikasi berasal dari image.

2. **Login ke GHCR** bila repositori privat, dengan token yang hanya memiliki izin `read:packages`:
   ```bash
   docker login ghcr.io -u widiartowahyu3
   ```

3. **Buat berkas rahasia.** Semuanya sudah diabaikan Git. Gunakan nilai heksadesimal, misalnya dari `openssl rand -hex 24`:
   ```bash
   cp .env.production.example .env.production
   cp src/.env.production.example .env.app
   mkdir -p secrets certs certbot-www
   chmod 700 secrets
   openssl rand -hex 24 > secrets/db_root_password.txt
   chmod 644 secrets/db_root_password.txt
   chmod 600 .env.production .env.app
   ```
   - `.env.production`: domain, port, kredensial `erp_app` dan `erp_migrator`.
   - `.env.app`: isi `APP_KEY` (`base64:` diikuti hasil `openssl rand -base64 32`) dan samakan `DB_PASSWORD` dengan `DB_APP_PASSWORD`. Kredensial `erp_migrator` dan root **dilarang** ada di berkas ini.

4. **Pasang sertifikat HTTPS.** Nginx di dalam stack melayani HTTPS langsung dan membaca `certs/fullchain.pem` serta `certs/privkey.pem`. Nginx berjalan sebagai UID 101, jadi kunci harus bisa dibaca UID tersebut:
   ```bash
   sudo certbot certonly --standalone -d erp.example.com
   sudo install -o 101 -g 101 -m 644 /etc/letsencrypt/live/erp.example.com/fullchain.pem certs/fullchain.pem
   sudo install -o 101 -g 101 -m 600 /etc/letsencrypt/live/erp.example.com/privkey.pem certs/privkey.pem
   ```
   Cara `--standalone` di atas hanya untuk penerbitan pertama, sebelum stack berjalan. Untuk perpanjangan, pakai `certbot renew --webroot -w certbot-www`, salin ulang kedua berkas, lalu muat ulang nginx:
   ```bash
   docker compose -p erp-trading-prod -f compose.prod.yaml --env-file .env.production exec web nginx -s reload
   ```
   Perintah ini butuh `ERP_IMAGE_TAG` di environment, isi dengan versi aktif dari `.deploy/current-tag`.

5. **Deploy pertama, seed referensi, dan buat admin:**
   ```bash
   bash scripts/deploy.sh v1.0.0
   export ERP_IMAGE_TAG=v1.0.0
   docker compose -p erp-trading-prod -f compose.prod.yaml --env-file .env.production run --rm --no-deps php php artisan db:seed --force
   docker compose -p erp-trading-prod -f compose.prod.yaml --env-file .env.production run --rm --no-deps php php artisan erp:admin:create
   ```

---

## 4. Deploy Versi Berikutnya

```bash
bash scripts/deploy.sh v1.1.0
```

Urutan kerja skrip:
1. Menarik image versi baru.
2. Mencadangkan database ke `backups/`.
3. Menjalankan migrasi dengan kredensial `erp_migrator` yang hanya disuntikkan ke container sekali pakai.
4. Mengganti container `web` dan `php`, lalu menguji `/up`.
5. Bila gagal, mengembalikan container ke versi sebelumnya dan keluar dengan exit 1.

**Batasan rollback:** migrasi tidak ikut dibatalkan. Bila skema baru tidak cocok dengan kode lama, pulihkan database dari berkas backup terakhir. Karena itu migrasi sebaiknya selalu kompatibel dengan versi sebelumnya.

---

## 5. Catatan Operasional

- **Backup belum terjadwal:** `scripts/deploy.sh` hanya mencadangkan saat deploy. Jadwalkan `scripts/db-backup.sh` lewat cron dengan `COMPOSE_FILE`, `COMPOSE_ENV_FILES`, `COMPOSE_PROJECT_NAME`, dan `ERP_IMAGE_TAG` terisi, lalu salin hasilnya ke luar server. Berkas backup tidak terenkripsi.
- **Isolasi jaringan:** container `php` dan `db` tidak memiliki akses internet. Bila kelak aplikasi perlu mengirim email lewat SMTP, jaringan ini perlu disesuaikan.
- **Pembatas laju nginx** memakai IP klien yang terlihat oleh nginx. Bila kelak ada proxy di depan, konfigurasi real IP dan `TRUSTED_PROXIES` wajib ditambahkan agar semua pengguna tidak terhitung sebagai satu IP.
- **Database uji:** `docker/mariadb/init/01-init.sh` juga membuat `erp_trading_test` di production. Database ini kosong dan tidak dipakai aplikasi.
- **Worker dan scheduler:** saat ini tidak ada job antrean atau jadwal Laravel, jadi service-nya tidak dibuat.
