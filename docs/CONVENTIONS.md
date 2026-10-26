# Konvensi Struktur & Standar Koding (Fase 1)

Dokumen ini mendefinisikan standar arsitektur kode, tata letak direktori, aturan koding backend PHP/Laravel, komponen frontend Vue 3, serta konvensi kontrol versi Git untuk proyek ERP Distribusi / Trading.

---

## 1. Struktur Direktori `src/`

Seluruh kode aplikasi berada di dalam direktori `src/` yang di-mount secara terisolasi ke container PHP:

```text
src/
├── app/
│   ├── Http/
│   │   ├── Controllers/          # Controller HTTP (Inertia & API)
│   │   └── Middleware/           # Middleware keamanan (SecurityHeaders, HandleInertiaRequests)
│   ├── Models/                   # Eloquent models (Strict Mode aktif)
│   └── Providers/                # Service provider (AppServiceProvider)
├── bootstrap/
│   ├── app.php                   # Konfigurasi aplikasi, middleware groups, trusted proxies
│   └── providers.php             # Registrasi service provider
├── config/                       # Konfigurasi Laravel (database, session, app, inertia)
├── database/
│   ├── factories/                # Model factories untuk testing
│   ├── migrations/               # Skema migrasi tabel (hanya DDL)
│   └── seeders/                  # Data seeder
├── public/                       # Web root Nginx (index.php, robots.txt, build/)
├── resources/
│   ├── css/                      # CSS styling (app.css, Tailwind 4 @theme)
│   ├── js/                       # Kode sumber frontend
│   │   ├── Layouts/              # Layout template (AppLayout, GuestLayout)
│   │   ├── Pages/                # Halaman komponen Inertia (Dashboard, Errors)
│   │   ├── config/               # Konfigurasi menu dan navigasi
│   │   └── app.js                # Entry point aplikasi Vue 3 & Inertia
│   └── views/                    # Root Blade template (app.blade.php)
├── routes/                       # Rute aplikasi (web.php, console.php)
├── storage/                      # Penyimpanan sementara (cache, sessions, logs)
└── tests/                        # Suite pengujian Pest
    ├── Arch/                     # Architecture tests (SecurityArchTest)
    ├── Feature/                  # Feature tests (SmokeTest, SecurityHeadersTest, dsb)
    ├── Unit/                     # Unit tests
    └── Pest.php                  # Konfigurasi global Pest
```

---

## 2. Standar Koding PHP & Laravel

### A. Strict Types
Setiap berkas PHP baru **wajib** diawali dengan deklarasi strict types:
```php
<?php

declare(strict_types=1);
```

### B. Eloquent Strict Mode
Aplikasi mengaktifkan strict mode untuk Eloquent di `AppServiceProvider::boot()`:
- `Model::shouldBeStrict(! app()->isProduction())`:
  - Mencegah *lazy loading* (N+1 query issue).
  - Melemparkan exception jika ada pengisian atribut yang tidak terdaftar (*mass assignment violation*).
  - Melemparkan exception saat mengakses atribut model yang tidak ada.

### C. Immutability Tanggal
Aplikasi menggunakan `CarbonImmutable` sebagai standar penanganan tanggal dan waktu melalui `Date::use(CarbonImmutable::class)`.

### D. Larangan Perintah Destruktif di Production
`DB::prohibitDestructiveCommands(app()->isProduction())` aktif untuk mencegah eksekusi perintah seperti `migrate:fresh` atau `migrate:reset` pada lingkungan production.

### E. Larangan Fungsi Berbahaya
Pest architecture test (`tests/Arch/SecurityArchTest.php`) secara otomatis melarang penggunaan fungsi-fungsi berikut pada namespace `App`:
`eval`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, `unserialize`, `assert`, `create_function`, `extract`, `dd`, `dump`, `var_dump`.

### F. Analisis Statis & Formatting
- **Larastan**: Wajib lolos analisis statis level 6 (`make analyse`).
- **Laravel Pint**: Wajib diformat sesuai standar Laravel PSR-12 (`make lint`).

---

## 3. Standar Frontend Vue 3 & UI

### A. Composition API & `<script setup>`
Semua komponen Vue 3 ditulis menggunakan Single File Component (SFC) dengan `<script setup>`:
```vue
<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
});
</script>
```

### B. Tanpa TypeScript
Sesuai arsitektur Fase 1, frontend menggunakan JavaScript standar (ES Module) tanpa lapisan TypeScript, menjaga build pipeline cepat dan sederhana.

### C. Aksesibilitas (WCAG AA Baseline)
- Rasio kontras teks terhadap latar belakang minimal 4.5:1 untuk teks normal.
- Semua ikon interaktif dari `lucide-vue-next` wajib memiliki atribut pendukung (mis. `aria-label` pada tombol, atau teks pendamping). Ikon dekoratif diberi `aria-hidden="true"`.
- Interaksi keyboard wajib menampilkan cincin fokus yang jelas (`focus-visible:ring-2 focus-visible:ring-indigo-600 focus-visible:outline-none`).

### D. Desain Token Tailwind CSS v4
- Konfigurasi token didefinisikan dalam `@theme` di `resources/css/app.css`.
- Palet: Warna primer indigo (`#4f46e5`), netral slate (`#0f172a`, `#64748b`, `#f8fafc`), status (emerald sukses, amber peringatan, rose bahaya, sky info).
- Border radius standar: `rounded-lg` (`0.5rem`).
- Spacing: Mengikuti grid konsisten 4px default Tailwind.

### E. Self-Hosted Font
Font Inter dimuat secara lokal melalui paket `@fontsource-variable/inter`. Dilarang keras mengimpor font melalui Google Fonts atau CDN pihak ketiga.

---

## 4. Konvensi Kontrol Versi Git

1. **Conventional Commits**: Format commit wajib mematuhi standar: `<type>(<scope>): <deskripsi pendek>` (mis. `feat(app): initialize laravel 13 skeleton`).
2. **Satu Commit Per Langkah Logis**: Setiap langkah pengerjaan dipisahkan dalam satu commit independen.
3. **Larangan Force & Bypass**: Dilarang keras menggunakan `git push --force` atau opsi `--no-verify`.
4. **Perlindungan Rahasia**: Tidak boleh ada berkas `.env`, kredensial, private key, atau kata sandi yang di-commit ke repositori.

---

## 5. Konvensi Skema Database (Fase 2)

1. **Engine & Charset**: Engine `InnoDB`, charset `utf8mb4`, collation `utf8mb4_unicode_ci`.
2. **Primary Key**: `id` bertipe `bigint unsigned auto-increment` (kecuali pivot composite PK).
3. **Presisi Numerik (Tanpa Float/Double)**:
   - Uang/Rupiah: `decimal(18,2)`
   - Kuantitas: `decimal(18,4)`
   - Harga & Biaya Satuan: `decimal(18,4)`
   - Tarif/Persentase: `decimal(5,2)`
4. **Foreign Key Policy**: `ON UPDATE RESTRICT ON DELETE RESTRICT` untuk semua relasi, kecuali `role_user` dan `permission_role` (`ON DELETE CASCADE`).
5. **Standar Audit & Immutabilitas**:
   - Master data & dokumen: `created_at`, `updated_at`, `created_by`, `updated_by` (FK users).
   - Append-only (`audit_logs`, `stock_movements`): Hanya `created_at` (+ `user_id`/`created_by`).
   - Soft delete (`deleted_at`) hanya pada data master. Dokumen transaksi dilarang dihapus (menggunakan status `void` + `voided_at`, `voided_by`, `void_reason`).
6. **Constraint CHECK Eksplisit**: Seluruh validasi nilai kolom status dan rentang numerik diwajibkan menggunakan constraint `CHECK` bernama eksplisit `chk_<tabel>_<nama>` via SQL statis literal.
7. **Nomor Dokumen**: `varchar(30) UNIQUE`, dikelola bebas celah via tabel `document_sequences`.
