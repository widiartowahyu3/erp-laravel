# ERP Distribusi / Trading (Laravel + Vue Monolith)

Repositori sistem ERP untuk operasional distribusi dan trading berbasis monolitik (Laravel + Vue) dengan standar arsitektur dan hardening tingkat tinggi.

---

## Status Fase: Fase 0 - Fondasi Lingkungan & Hardening Dasar (LULUS VERIFIKASI)

Fase 0 berfokus pada penyiapan lingkungan kerja yang aman, reproducible, dan terisolasi:
- **Host**: WSL 2 Ubuntu + Docker Desktop / Docker Engine.
- **Git Repository**: Bebas dari rahasia, dilindungi hook otomatis (Gitleaks, filter rahasia, Conventional Commits).
- **Infrastruktur Docker**:
  - PHP-FPM 8.4 hardened (Multi-stage, unprivileged UID 1000, read-only rootfs, no-new-privileges, cap-drop ALL, disable_functions pada FPM).
  - Nginx Unprivileged 1.28 (Reverse proxy unprivileged, port 8080, pembatasan front controller, pemblokiran file sensitif).
  - MariaDB 12.3 LTS (Hardened parameter, pemisahan hak akses `erp_app` DML strictly vs `erp_migrator` DDL, internal network).
  - Node 22 LTS (Tooling dev/Vite terisolasi).
  - Otomatisasi pengujian hardening 100% PASS via `make verify`.

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
