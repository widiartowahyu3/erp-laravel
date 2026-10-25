# Daftar Stack & Versi Image / Tool (Fase 1 - Final)

Dokumen ini mencatat seluruh image Docker, runtime, framework, dependensi, dan kakas (tools) resmi yang digunakan pada Fase 1. Seluruh versi yang dipilih adalah rilis **STABIL** (General Availability / LTS), bukan versi alpha, beta, Release Candidate (RC), atau rolling release (`latest` dilarang). Seluruh image pihak ketiga dipin menggunakan format `tag@sha256:digest` untuk menjamin reproduktibilitas mutlak dan mencegah supply chain attacks.

Tanggal Pengecekan & Verifikasi: **2026-10-25**

---

## 1. Komponen Infrastruktur Docker

| Komponen / Layanan | Base Image Resmi | Tag Terverifikasi | Digest Image (SHA256) | Keterangan & Alasan Pemilihan |
|---|---|---|---|---|
| **PHP-FPM Runtime** | `library/php` | `8.5.12-fpm-alpine` | `sha256:4c2818074ec49f6abe46620f3965e0cb75bc3a5f2b417edd1aacaba6f091c40e` | Rilis patch stabil terbaru PHP 8.5. OPcache built-in. Ekstensi: `pdo_mysql`, `intl`, `bcmath`, `zip`. Dilengkapi `cgi-fcgi` pada target `dev`. `pcntl` dilarang dan dieliminasi. |
| **Composer** | `library/composer` | `2.10.3` | `sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc` | Composer seri 2.10.x stabil terbaru, dipin untuk build stage development multi-stage PHP. |
| **Web Server (Reverse Proxy)** | `nginxinc/nginx-unprivileged` | `1.30.6-alpine` | `sha256:f5116ed4606f3eb015dd4a8e28cb9b351476dc0ad4bc6e9f93b19cec130cdf03` | Nginx seri stabil terbaru 1.30.x-alpine. Berjalan default sebagai unprivileged user UID 101. |
| **Database Server** | `library/mariadb` | `12.3.3` | `sha256:99c125f103f5d081ebbc9ffe064e63daf7f688ac33a5f7f29af2d7260a96debf` | MariaDB 12.3 Long-Term Support (LTS) stabil terbaru. Root locked to localhost. Pemisahan role DML `erp_app` vs DDL `erp_migrator`. |
| **Node.js (Tooling & Vite Dev)** | `library/node` | `24.22.1-alpine` | `sha256:06d7adf3e0ef61a13f1d28682726366f97a15aa1888c934d3ecec86c33918b53` | Node.js Active LTS (seri 24.x). Dijalankan non-root UID/GID host dan volume cache npm writable. |
| **Secret Scanner (Hook & CI)** | `zricethezav/gitleaks` | `v8.30.1` | `sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f` | Standar industri untuk secret scanning pada pre-commit hook dan CI/CD pipeline. |

---

## 2. Framework Backend & Dependensi PHP

| Paket / Komponen | Versi Terkunci (Lock) | Kategori | Keterangan & Fungsi |
|---|---|---|---|
| **Laravel Framework** | `13.38.0` | Backend Core | Framework monolith inti modern |
| **Laravel Skeleton** | `13.11.0` | Skeleton App | Struktur dasar boilerplate aplikasi di `src/` |
| **Inertia Laravel** | `3.5.2` | Adapter Inertia | Adapter server-side Inertia.js untuk monolith Laravel + Vue |
| **Carbon** | `3.12.0` | Utility | Penanganan tanggal dan waktu immutable (`CarbonImmutable`) |
| **Roave Security Advisories**| `dev-latest` | Security Dev | Mencegah instalasi paket Composer dengan celah keamanan yang diketahui |
| **Laravel Tinker** | `3.0.2` | Dev Tooling | REPL interaktif, terisolasi pada `require-dev` (tidak masuk ke image production) |

---

## 3. Tooling Kualitas & Pengujian Backend

| Alat / Paket | Versi Terkunci (Lock) | Kategori | Keterangan & Standar |
|---|---|---|---|
| **Pest PHP** | `4.7.8` | Testing Framework | Framework pengujian modern elegan |
| **Pest Plugin Laravel** | `4.1.0` | Testing Integration | Integrasi helper dan testing assertion Laravel |
| **Pest Plugin Arch** | `4.0.2` | Architecture Test | Pengujian arsitektur & larangan fungsi berbahaya di `app/` |
| **PHPUnit** | `12.5.33` | Testing Core | Runner pengujian inti di bawah Pest |
| **Larastan** | `3.13.2` | Static Analysis | Extension PHPStan untuk Laravel (level 6 strict) |
| **PHPStan** | `2.3.2` | Static Analysis Engine | Mesin analisis statis kode PHP |
| **Laravel Pint** | `1.32.1` | Code Formatter | Penegakan standar gaya koding Laravel & PSR-12 |

---

## 4. Framework Frontend & Dependensi UI

| Paket NPM | Versi Terpasang | Kategori | Keterangan & Fungsi |
|---|---|---|---|
| **Vue.js** | `3.5.44` | UI Framework | Reaktif frontend framework (Composition API, `<script setup>`) |
| **@inertiajs/vue3** | `3.9.1` | Client Adapter | Client-side routing Inertia tanpa rendering SPA router terpisah |
| **Tailwind CSS** | `4.3.3` | Styling Engine | Utilitas styling modern seri 4 via `@theme` tokens (versi stabil terbaru) |
| **@tailwindcss/vite** | `4.3.3` | Vite Integration | Integrasi langsung Tailwind CSS v4 ke dalam Vite (versi stabil terbaru) |
| **Vite** | `8.3.5` | Bundler & Dev Server | Build tool performa tinggi dengan dukungan HMR container |
| **laravel-vite-plugin** | `3.2.0` | Vite Integration | Plugin integrasi Vite untuk asset bundling Laravel |
| **lucide-vue-next** | `1.0.0` | UI Icons | Kumpulan ikon SVG konsisten untuk interface pengguna |
| **@fontsource-variable/inter**| `5.3.0` | Self-hosted Font | Font Inter Variable self-hosted (tanpa Google Fonts/CDN) |

---

## 5. Tooling Kualitas & Formatting Frontend

| Alat NPM | Versi Terpasang | Kategori | Keterangan |
|---|---|---|---|
| **ESLint** | `10.13.0` | Code Linter | Linter JavaScript/Vue berbasis flat config `eslint.config.js` |
| **eslint-plugin-vue** | `10.11.1` | Vue Linter Rules | Aturan linting khusus SFC Vue 3 |
| **Prettier** | `3.9.10` | Code Formatter | Formatter terpadu untuk JS, Vue SFC, dan CSS |

---

## 6. Kakas Host (WSL 2 Ubuntu)

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
