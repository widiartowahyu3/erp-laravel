# ERP Distribusi / Trading (Laravel + Vue Monolith)

Repositori sistem ERP untuk operasional distribusi dan trading berbasis monolitik (Laravel + Vue) dengan standar arsitektur dan hardening tingkat tinggi.

---

## Status Fase: Fase 0 - Fondasi Lingkungan & Hardening Dasar (REVISI - LULUS VERIFIKASI)

Fase 0 berfokus pada penyiapan lingkungan kerja yang aman, reproducible, dan terisolasi:
- **Host**: WSL 2 Ubuntu + Docker Desktop / Docker Engine.
- **Git Repository**: Bebas dari rahasia, dilindungi hook otomatis (Gitleaks v8.30.1, filter rahasia heuristik ketat, Conventional Commits).
- **Infrastruktur Docker**:
  - **PHP-FPM 8.5 hardened** (Multi-stage, unprivileged UID 1000, read-only rootfs, no-new-privileges, cap-drop ALL, disable_functions pada FPM, cgi-fcgi untuk probe independen).
  - **Nginx Unprivileged 1.30** (Reverse proxy unprivileged, port 8080, pembatasan front controller /index.php, pemblokiran anchored path terhadap direktori dan berkas sensitif).
  - **MariaDB 12.3 LTS** (Hardened parameter, Docker secrets untuk kata sandi root, root terkunci hanya untuk localhost, pemisahan hak akses `erp_app` DML strictly vs `erp_migrator` DDL, internal network).
  - **Node 24 Active LTS** (Tooling dev/Vite non-root terisolasi, cache npm writable pada read-only rootfs).
  - **Pemisahan Kode Aplikasi & Infra**: Seluruh kode aplikasi diisolasi di direktori `src/`. Container tidak memiliki akses mount ke root repositori, `.git`, `.env` infra, maupun direktori `secrets/`.
  - **Otomatisasi pengujian hardening** 100% PASS via `make verify`.

> [!WARNING]
> **Peringatan Alokasi Memori Docker Desktop / WSL 2**:
> Saat ini alokasi memori default WSL 2 / Docker Desktop tercatat sekitar **3,7 GB**. Sebelum melangkah ke **Fase 1** (pemasangan Laravel, dependensi Composer, Tailwind, dan build frontend Vite), pemilik proyek **sangat disarankan** menaikkan batas memori WSL minimal menjadi **8 GB** (atau 12 GB bila RAM host 16 GB+) untuk mencegah kehabisan memori (*Out Of Memory* / OOM kill) saat kompilasi asset.
>
> Konfigurasi dapat dibuat pada sisi host Windows di berkas `%USERPROFILE%\.wslconfig`:
> ```ini
> [wsl2]
> memory=8GB
> processors=4
> swap=4GB
> ```
> Setelah menyimpan berkas tersebut, jalankan `wsl --shutdown` di PowerShell lalu buka kembali terminal WSL Ubuntu.

> **Catatan:** Sesuai spesifikasi Fase 0, belum ada instalasi Laravel, Vue, tabel aplikasi, maupun kode bisnis. Implementasi aplikasi dimulai pada Fase 1.

---

## Panduan Setup Lingkungan dari Nol (Quickstart)

Ikuti langkah-langkah berikut untuk menjalankan dan memverifikasi lingkungan:

### 1. Clone Repositori & Konfigurasi Git Hooks
Pastikan clone dilakukan di dalam filesystem Linux WSL native (mis. `~/projects/`):
```bash
git clone git@github.com:widiartowahyu3/erp-laravel.git erp-trading
cd erp-trading

# Aktifkan hook keamanan Git lokal (Gitleaks, filter rahasia, conventional commits)
make hooks
```

### 2. Inisialisasi Berkas Lingkungan & Rahasia
Salin berkas template dan buat rahasia lokal aman:
```bash
cp .env.example .env

# Generate kata sandi acak kuat untuk user aplikasi & migrator
APP_PASS=$(openssl rand -hex 24)
MIGRATOR_PASS=$(openssl rand -hex 24)

sed -i "s/change_this_to_a_secure_random_app_password/${APP_PASS}/" .env
sed -i "s/change_this_to_a_secure_random_migrator_password/${MIGRATOR_PASS}/" .env
chmod 600 .env

# Buat berkas kata sandi root MariaDB terpisah (Docker Compose secrets)
openssl rand -hex 24 > secrets/db_root_password.txt
chmod 600 secrets/db_root_password.txt
```

### 3. Build & Jalankan Stack Docker
```bash
# Build image PHP dev
make build

# Jalankan stack container
make up

# Periksa status kontainer
make ps
```

### 4. Jalankan Skrip Verifikasi Hardening
Pastikan seluruh 38 butir pemeriksaan berstatus **PASS 100%**:
```bash
make verify
```

---

## Dokumentasi Teknis

- [Daftar Stack & Versi Image / Tool](docs/STACK.md)
- [Panduan Docker & Operasional WSL](docs/DOCKER.md)
- [Kebijakan & Standar Hardening Keamanan](docs/SECURITY.md)
- [Kebijakan & Alur Kerja Git](docs/GIT.md)
