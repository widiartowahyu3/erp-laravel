# Daftar Stack & Versi Image / Tool (Fase 0)

Dokumen ini mencatat seluruh image Docker, runtime, dan kakas (tools) resmi yang digunakan pada Fase 0. Seluruh versi yang dipilih adalah rilis **STABIL** (General Availability / LTS), bukan versi alpha, beta, Release Candidate (RC), atau rolling release (`latest` dilarang).

---

## 1. Komponen Infrastruktur Docker

| Komponen / Layanan | Base Image Resmi | Tag Terverifikasi | Digest Image (SHA256) | Keterangan & Alasan Pemilihan |
|---|---|---|---|---|
| **PHP-FPM Runtime** | `library/php` | `8.4.27-fpm-alpine` | `sha256:6b83fd767e1902ae4c89fe11f929f02e78bf769215c703060ac8a7c4be672c8c` | Rilis stabil terbaru PHP 8.4 yang didukung Laravel 13. Varian Alpine untuk footprint minimal dan attack surface kecil. Ekstensi: `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `opcache`, `zip`. `pcntl` sengaja tidak dipasang. |
| **Composer** | `library/composer` | `2.8.12` | `sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c` | Composer v2 stabil terbaru dipin untuk build stage development. |
| **Web Server (Reverse Proxy)** | `nginxinc/nginx-unprivileged` | `1.28-alpine` | `sha256:7377697a821c131a924a7105fafbe7414db4e9fcc77a6f08f776f33f141ec3f8` | Nginx versi stabil (1.28) unprivileged resmi. Berjalan default sebagai user non-root UID 101. |
| **Database Server** | `library/mariadb` | `12.3.3` | `sha256:99c125f103f5d081ebbc9ffe064e63daf7f688ac33a5f7f29af2d7260a96debf` | MariaDB 12.3 Long-Term Support (LTS). Seri 13.x dilarang (rolling release). |
| **Node.js (Tooling & Vite Dev)** | `library/node` | `22-alpine` | `sha256:fe4f6d74e20370acb04ad889077d14d0f64a9c8ea6a42bc60cddf1e844699914` | Node.js Active LTS (v22). Digunakan strictly pada profil `tools` dan `dev` untuk kompilasi frontend Vite tanpa berjalan di production runtime. |
| **Secret Scanner (Hook & CI)** | `zricethezav/gitleaks` | `v8.30.1` | `sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f` | Standar industri untuk deteksi kebocoran kredensial dan secret scanning. |

---

## 2. Kakas Host (WSL Ubuntu)

| Kakas Host | Versi Terpasang | Peran |
|---|---|---|
| **OS / Distribusi** | Ubuntu 26.04 LTS (Resolute Raccoon) | Sistem operasi host WSL 2 |
| **Linux Kernel** | 6.18.54.1-microsoft-standard-WSL2 | Kernel WSL 2 |
| **Docker Engine** | 29.8.2 | Container runtime |
| **Docker Compose** | v5.6.0 | Orkestrasi container lokal |
| **Git** | 2.53.0 | Version control system |
| **OpenSSH** | OpenSSH_10.2p1 | Komunikasi aman remote Git via SSH |
| **GNU Make** | 4.4.1 | Otomatisasi perintah pengembang & verifikasi |
| **cURL** | 8.18.0 | Pengujian HTTP endpoint & healthcheck |
| **OpenSSL** | 3.5.5 | Pembuatan entropy dan secret random aman |
