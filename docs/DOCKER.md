# Panduan Operasional Docker & Lingkungan WSL

Dokumen ini menjelaskan arsitektur container, cara penggunaan perintah `make`, dan panduan optimasi lingkungan WSL 2 untuk proyek ERP Distribusi / Trading.

---

## 1. Struktur Direktori Docker

```text
erp-trading/
├── docker/
│   ├── php/
│   │   ├── Dockerfile                   # Multi-stage build (base, dev, prod)
│   │   └── conf/
│   │       ├── php-common.ini           # Konfigurasi dasar PHP (FPM & CLI)
│   │       ├── php-fpm-hardening.conf   # Hardening ketat pool FPM (disable_functions, open_basedir)
│   │       └── php-cli.ini              # Konfigurasi khusus CLI (tanpa disable_functions)
│   ├── nginx/
│   │   └── default.conf                 # Hardening reverse proxy, front controller, pemblokiran path
│   └── mariadb/
│       ├── conf.d/
│       │   └── hardening.cnf            # Parameter keamanan MariaDB (utf8mb4, local_infile=0)
│       └── init/
│           └── 01-init.sh               # Inisialisasi DB erp_trading & user erp_app/erp_migrator
├── compose.yaml                         # Orkestrasi Docker Compose lokal
├── Makefile                             # Antarmuka perintah operasional
├── scripts/
│   └── verify-hardening.sh              # Skrip otomasi uji kepatuhan hardening
├── .env.example                         # Template variabel lingkungan non-rahasia
└── src/
    └── public/
        └── .gitkeep                     # Web root direktori publik aplikasi (Laravel)
```

---

## 2. Cara Pakai & Perintah `make`

Semua interaksi dengan Docker distandarisasi melalui kakas `make`:

| Perintah | Deskripsi Fungsi |
|---|---|
| `make up` | Menjalankan seluruh layanan (`web`, `php`, `db`) di latar belakang (`-d`) |
| `make down` | Menghentikan seluruh container yang berjalan tanpa menghapus volume data |
| `make build` | Membangun ulang image Docker lokal (`php:dev`) |
| `make logs` | Menampilkan dan mengikuti log gabungan seluruh container (`docker compose logs -f`) |
| `make ps` | Melihat status dan healthcheck container yang sedang berjalan |
| `make shell` | Masuk ke dalam shell container `php` sebagai user non-root (`appuser`) |
| `make db` | Membuka interactive shell klien database MariaDB dengan otentikasi root lokal |
| `make composer cmd='...'` | Menjalankan perintah Composer di dalam container PHP terisolasi (mis. `make composer cmd='--version'`) |
| `make npm cmd='...'` | Menjalankan perintah NPM via container Node profil tools (mis. `make npm cmd='--version'`) |
| `make verify` | Menjalankan rangkaian uji keamanan otomatis (`scripts/verify-hardening.sh`) |
| `make fresh` | **Hati-hati**: Menghentikan container dan menghapus seluruh volume (meminta konfirmasi interaktif `y/N`) |

---

## 3. Catatan Khusus Lingkungan WSL 2

1. **Letak Filesystem Proyek**:
   - Proyek **WAJIB** berada di dalam filesystem native Linux WSL (misalnya `~/projects/erp-trading` atau `/home/widi/projects/...`).
   - Dilarang keras menempatkan proyek di mount Windows (`/mnt/c/...`). Menjalankan Docker bind mount di `/mnt/c` menyebabkan masalah permission file Linux (`chmod`/`chown`), performa I/O yang lambat, dan kegagalan inisialisasi soket/database.

2. **Pengaturan Batas Memori WSL 2 (`.wslconfig`)**:
   - Alokasi RAM WSL 2 diatur di sisi Windows host melalui berkas `C:\Users\<User>\.wslconfig`.
   - **Saran Konfigurasi Rekomendasi**:
     ```ini
     [wsl2]
     memory=6GB        # Alokasikan RAM sesuai kapasitas fisik host (minimal 4GB untuk stack ERP)
     processors=4      # Jumlah vCPU
     swap=2GB          # Alokasi swap space
     localhostForwarding=true
     ```
   - Catatan: Perubahan `.wslconfig` memerlukan restart WSL dari PowerShell Windows (`wsl --shutdown`).
