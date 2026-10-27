# Konvensi Struktur & Standar Koding (Fase 3)

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

---

## 6. Konvensi Autentikasi, Otorisasi RBAC, dan Sesi (Fase 3)

1. **Model Autentikasi**:
   - Model `App\Models\User` extends `Illuminate\Foundation\Auth\User`, menggunakan `SoftDeletes`.
   - `$fillable` eksplisit: `['name', 'email', 'password', 'is_active']`.
   - `$hidden`: `['password', 'remember_token']`.
   - `$casts`: `password => 'hashed'`, `is_active => 'boolean'`, `last_login_at => 'datetime'`.
2. **Kredensial & Kebijakan Kata Sandi Terpadu (`PasswordPolicy`)**:
   - Standar hash wajib `Argon2id` via `config/hashing.php` dan `HASH_DRIVER=argon2id`.
   - **Satu Aturan Tunggal**: Seluruh validasi kata sandi (pembuatan admin CLI, update password form, reset password CLI) **wajib** menggunakan kelas tunggal `App\Support\PasswordPolicy` (`PasswordPolicy::rules($emailOrFieldName)`). Dilarang keras menduplikasi aturan validasi kata sandi secara lepas di controller atau FormRequest.
   - Ketentuan Aturan:
     - Minimal 12 karakter, maksimal 128 karakter.
     - Wajib memuat minimal satu huruf besar (*uppercase*), satu huruf kecil (*lowercase*), dan satu angka (*number*). Karakter simbol diperbolehkan namun tidak dipaksakan guna mendukung passphrase panjang dengan entropi tinggi.
     - Dilarang memuat bagian lokal dari alamat email pengguna (`email_local_part`), jika bagian lokal email memiliki panjang minimal 4 karakter (menghindari false positive untuk email dengan karakter pendek).
   - Validasi CLI interaktif: Perintah Artisan menggunakan `PasswordPolicy::validateDirectly($password, $email)` yang mengembalikan array pesan kesalahan yang seragam.
3. **Konvensi Hak Akses (Permissions)**:
   - Format wajib: `<resource>.<action>` dalam huruf kecil dan *snake_case* (mis. `purchase_orders.approve`, `users.view`).
   - Modul resmi: `administration`, `master_data`, `purchasing`, `inventory`, `sales`, `finance`, `reporting`.
4. **Otorisasi & Gate Caching**:
   - Otorisasi rute wajib menggunakan middleware `can:<permission>` (mis. `middleware(['auth', 'user.active', 'can:users.view'])`).
   - Evaluasi hak akses menggunakan `Gate::before` terintegrasi dengan in-memory cache pada model `User` (`$permissionNamesCache`) untuk menjamin performa O(1) tanpa query N+1.
   - Frontend navigation menyembunyikan item yang tidak memiliki izin sesuai `auth.permissions` yang dibagikan oleh `HandleInertiaRequests`.
5. **Kebijakan Sesi & Keamanan**:
   - Penyimpanan sesi wajib berbasis database (`SESSION_DRIVER=database`).
   - Timeout ganda: *Idle timeout* 120 menit dan *Absolute lifetime* 480 menit (8 jam).
   - Middleware `EnsureUserIsActive` memvalidasi status keaktifan pengguna pada setiap request terautentikasi.
6. **Audit Peristiwa Keamanan**:
   - Setiap peristiwa autentikasi dan penolakan hak akses wajib dicatat via `App\Services\Audit\AuditLogger`.
   - Data sensitif (password, token, raw email) wajib disanitasi secara otomatis sebelum disimpan ke tabel `audit_logs`.
   - Peristiwa berulang dengan frekuensi tinggi (`login.throttled` dan `access.denied`) dideduplikasi secara otomatis selama 60 detik melalui atomic lock cache guna mencegah DoS kapasitas tabel audit.
7. **Pola Standar Exception 429 (`TooManyAttemptsException`)**:
   - Dilarang memodifikasi status code `ValidationException` menjadi 429 yang dapat merusak alur redirect Inertia.
   - Untuk seluruh skenario pembatasan laju (*rate limiting* / *throttling*), gunakan exception khusus `App\Exceptions\TooManyAttemptsException`.
   - Respons otomatis disesuaikan secara terpadu:
     - **Inertia Requests**: Mengembalikan HTTP 303 Redirect kembali ke rute pemanggil beserta session flash errors dan header `Retry-After`. Frontend merender alert banner tingkat komponen ramah pengguna tanpa memicu dialog modal error raw 429 bawaan Inertia.
     - **API / JSON Requests**: Mengembalikan respons standar HTTP 429 *Too Many Requests* dengan payload JSON `{ "message": "...", "seconds": ... }` dan header `Retry-After`.
8. **Privasi Kunci Cache Rate Limiter**:
   - Kunci cache pembatas laju wajib melakukan hashing SHA-256 terhadap alamat email (`login:email:<sha256>`), dilarang keras menyimpan alamat email mentah di dalam tabel cache basis data.

---

## 7. Konvensi Fondasi Backend & Logika Bisnis (Fase 4)

1. **Aritmetika Desimal Aman**:
   - Seluruh operasi matematika yang melibatkan uang, harga, diskon, pajak, kuantitas stok, dan rasio konversi **DILARANG KERAS** menggunakan tipe numerik floating-point (`float` atau `double`).
   - Wajib menggunakan kelas utilitas final `App\Support\Decimal` yang beroperasi menggunakan ekstensi PHP `bcmath` dengan representasi nilai berupa `string`.
   - Pembulatan wajib menggunakan `Decimal::round()` dengan strategi *half-up away from zero* pada presisi yang telah distandarkan (`SCALE_MONEY` = 2, `SCALE_QTY` = 4, `SCALE_CONVERSION` = 6, `SCALE_INTERNAL` = 8).

2. **Status Enums & State Machine Dokumen**:
   - Seluruh nilai status dokumen transaksi wajib didefinisikan sebagai string-backed enum (`BackedEnum`) di dalam namespace `App\Enums`.
   - Transisi status dokumen wajib divalidasi melalui `App\Services\Documents\DocumentStateMachine`. Dilarang mengubah status dokumen secara bebas di controller tanpa melewati validasi matriks transisi.
   - Penambahan nilai status baru wajib menyinkronkan klausa MariaDB `CHECK (status IN (...))` dan diverifikasi kelulusannya oleh `EnumDriftTest`.

3. **Penomoran Dokumen Otomatis**:
   - Penomoran dokumen transaksi wajib menggunakan `App\Services\Documents\DocumentNumberService`.
   - Format nomor dokumen baku: `{PREFIX}/{YYYYMM}/{NNNNN}`.
   - Penomoran harus dijalankan di dalam transaksi database aktif menggunakan penguncian baris pesimistik (`lockForUpdate()`) pada tabel `document_sequences` untuk menjamin urutan bebas celah (*gapless*).

4. **Penjaga Transaksi (`RequiresTransaction`)**:
   - Metode mutasi multi-tabel atau operasi kritis wajib menyertakan trait `App\Support\Concerns\RequiresTransaction` dan memanggil `self::ensureInTransaction()`. Operasi di luar blok transaksi aktif akan langsung melempar `LogicException`.

5. **Query Daftar Aman (Pagination, Filter, Search, Sort)**:
   - Request parameter untuk index/listing data wajib divalidasi oleh `App\Http\Requests\Support\ListRequest` (maksimal `per_page` bernilai 100).
   - Eksekusi query listing wajib menggunakan `App\Support\Query\ListQuery` dengan whitelist kolom pengurutan dan filter yang eksplisit.
   - Karakter wildcard SQL `LIKE` (`%`, `_`, `\`) pada parameter pencarian wajib disanitasi secara otomatis agar diperlakukan sebagai karakter pencarian literal.
   - Pengurutan pada kolom non-unik wajib menyertakan secondary sort `id ASC` otomatis demi integritas cursor pagination.
   - Respons paginasi untuk Inertia wajib dibungkus menggunakan `App\Support\Inertia\Paginated::from()`.

6. **Jejak Audit Model**:
   - Model Eloquent master data dan dokumen transaksi wajib menyertakan trait `App\Models\Concerns\HasAuditUsers` (mengisi `created_by`, `updated_by`, `deleted_by`) dan `App\Models\Concerns\RecordsAudit` (merekam perubahan nilai kolom sebelum dan sesudah mutasi ke tabel `audit_logs`).
   - Nilai atribut lama wajib disimpan sementara di dalam *in-memory static store* internal guna mencegah modifikasi atribut Eloquent model secara langsung.

7. **Hierarki Exception Bisnis**:
   - Seluruh exception domain bisnis wajib merupakan turunan dari `App\Exceptions\BusinessRuleException` dan mengimplementasikan trait `App\Exceptions\Concerns\RendersAsFormError`.
   - Transisi dokumen tidak sah dan operasi pada dokumen terkunci mengembalikan HTTP 409 Conflict.
   - Pelanggaran validasi ketersediaan stok atau parameter kuantitas mengembalikan HTTP 422 Unprocessable Content.
   - Permintaan formulir Inertia secara otomatis diarahkan kembali via HTTP 303 Redirect ke halaman asal dengan pesan error di dalam form error bag (`withErrors(['general' => $message])`).
   - Exception bisnis dan throttling didaftarkan pada `$exceptions->dontReport(...)` di `bootstrap/app.php` guna mencegah pemborosan kapasitas log produksi.
