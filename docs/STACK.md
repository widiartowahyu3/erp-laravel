# Daftar Stack & Versi Image / Tool (Fase 0 - Revisi)

Dokumen ini mencatat seluruh image Docker, runtime, dan kakas (tools) resmi yang digunakan pada Fase 0. Seluruh versi yang dipilih adalah rilis **STABIL** (General Availability / LTS), bukan versi alpha, beta, Release Candidate (RC), atau rolling release (`latest` dilarang). Seluruh image pihak ketiga dipin menggunakan format `tag@sha256:digest` untuk menjamin reproduktibilitas mutlak dan mencegah supply chain attacks.

Tanggal Pengecekan & Verifikasi: **2026-10-25**

> [!IMPORTANT]
> **Aturan Tata Kelola Versi**: Seluruh versi image, dependensi, dan kakas wajib diverifikasi ulang status stabilitas, dukungan keamanan (EOL/LTS), serta keamanannya di awal setiap fase sebelum pengerjaan fase tersebut dimulai.

---

## 1. Komponen Infrastruktur Docker

| Komponen / Layanan | Base Image Resmi | Tag Terverifikasi | Digest Image (SHA256) | Keterangan & Alasan Pemilihan |
|---|---|---|---|---|
| **PHP-FPM Runtime** | `library/php` | `8.5.12-fpm-alpine` | `sha256:4c2818074ec49f6abe46620f3965e0cb75bc3a5f2b417edd1aacaba6f091c40e` | Rilis patch stabil terbaru PHP 8.5. OPcache sudah bawaan (built-in). Ekstensi dikompilasi: `pdo_mysql`, `intl`, `bcmath`, `zip`. Dilengkapi `fcgi` (`cgi-fcgi`) pada target `dev` untuk probe status FPM independen. `pcntl` sengaja tidak dipasang. |
| **Composer** | `library/composer` | `2.10.3` | `sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc` | Composer seri 2.10.x stabil terbaru, dipin untuk build stage development multi-stage PHP. |
| **Web Server (Reverse Proxy)** | `nginxinc/nginx-unprivileged` | `1.30.6-alpine` | `sha256:f5116ed4606f3eb015dd4a8e28cb9b351476dc0ad4bc6e9f93b19cec130cdf03` | Nginx seri stabil terbaru 1.30.x-alpine (minimal 1.30.4). Seri 1.28 sudah EOL dan dilarang. Berjalan default sebagai unprivileged user UID 101. |
| **Database Server** | `library/mariadb` | `12.3.3` | `sha256:99c125f103f5d081ebbc9ffe064e63daf7f688ac33a5f7f29af2d7260a96debf` | MariaDB 12.3 Long-Term Support (LTS) stabil terbaru. Seri 13.x dilarang (rolling release). |
| **Node.js (Tooling & Vite Dev)** | `library/node` | `24.22.1-alpine` | `sha256:06d7adf3e0ef61a13f1d28682726366f97a15aa1888c934d3ecec86c33918b53` | Node.js Active LTS saat ini (seri 24.x). Seri 22 dilarang karena sudah masuk masa Maintenance, dan seri 26 belum berstatus LTS. Dijalankan dengan build image non-root UID/GID host dan volume cache npm yang dapat ditulis. |
| **Secret Scanner (Hook & CI)** | `zricethezav/gitleaks` | `v8.30.1` | `sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f` | Standar industri untuk secret scanning pada pre-commit hook dan CI/CD pipeline. |

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
