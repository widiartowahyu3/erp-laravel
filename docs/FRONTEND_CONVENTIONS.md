# Panduan Konvensi Frontend ERP Distribusi/Trading

Dokumen ini mendefinisikan aturan dan konvensi pengembangan frontend untuk arsitektur monolith Vue 3 + Inertia 3 + Tailwind CSS 4 pada proyek ERP Distribusi/Trading.

---

## 1. Struktur Folder `resources/js`

```text
resources/js/
├── app.js                          # Titik masuk utama aplikasi Inertia & Vue
├── test-setup.js                   # Setup mock environment untuk Vitest / JSDOM
├── Components/
│   └── ui/                         # Design System (Komponen Primitif & Komposit)
│       ├── Button.vue
│       ├── TextInput.vue
│       ├── FormField.vue
│       ├── DataTable.vue
│       ├── Modal.vue
│       ├── Rupiah.vue
│       ├── ...
│       └── __tests__/              # Unit test komponen UI
│           ├── primitives.test.js
│           └── composites.test.js
├── composables/                    # Composable hooks logika bisnis & antarmuka
│   ├── useConfirm.js
│   ├── useFormatters.js
│   ├── useListQuery.js
│   ├── usePermission.js
│   ├── useTheme.js
│   ├── useToast.js
│   └── __tests__/                  # Unit test composables
├── config/                         # Konfigurasi frontend statis
│   └── navigation.js               # Struktur grup menu modul bisnis & izin
├── Layouts/                        # Layout halaman
│   ├── AppLayout.vue               # Layout utama aplikasi terautentikasi (sidebar + topbar)
│   └── GuestLayout.vue             # Layout halaman publik / tamu (login)
├── Pages/                          # Halaman-halaman Inertia
│   ├── Auth/
│   │   └── Login.vue               # Halaman autentikasi login
│   ├── Account/
│   │   └── Password.vue            # Halaman ubah kata sandi
│   ├── Dashboard.vue               # Halaman beranda modul operasional
│   ├── Dev/
│   │   └── Styleguide.vue          # Halaman katalog styleguide internal (local-only)
│   └── Error.vue                   # Halaman error fallback (403, 404, 500)
└── test/                           # Pengujian global & keamanan frontend
    ├── accessibility.test.js       # Pengujian otomatis axe-core
    ├── setup.test.js               # Pengujian verifikasi setup test runner
    ├── static-compliance.test.js   # Audit statis (anti-v-html, anti-inline-style, anti-CDN)
    └── theme-contrast.test.js      # Verifikasi matematis rasio kontras WCAG AA
```

---

## 2. Aturan Penamaan Berkas & Komponen

1. **Komponen Vue (`.vue`)**:
   - Selalu menggunakan format **PascalCase**: `FormField.vue`, `StatusBadge.vue`, `DataTable.vue`.
   - Nama komponen wajib jelas, deskriptif, dan mencerminkan fungsinya dalam *design system*.
2. **Composables & Utilitas (`.js`)**:
   - Selalu menggunakan format **camelCase** dengan awalan `use` untuk composable: `useConfirm.js`, `useListQuery.js`, `useFormatters.js`.
   - File konfigurasi menggunakan format **kebab-case** atau **camelCase**: `navigation.js`.
3. **File Pengujian (`.test.js`)**:
   - Nama file pengujian diletakkan di dalam folder `__tests__/` atau `test/` dengan akhiran `.test.js`.

---

## 3. Aturan Penggunaan Composables

1. **`usePermission`**:
   - Digunakan untuk memeriksa otorisasi berbasis RBAC di sisi frontend:
     ```js
     import { usePermission } from '@/composables/usePermission';
     const { can, canAny, canAll } = usePermission();

     if (can('sales_orders.create')) {
         // Tampilkan tombol tambah pesanan
     }
     ```
   - Alternatif deklaratif dalam template: gunakan komponen `<Can permission="sales_orders.create">...</Can>`.
2. **`useConfirm`**:
   - Digunakan untuk memunculkan modal konfirmasi dengan pola *Promise*:
     ```js
     import { useConfirm } from '@/composables/useConfirm';
     const { confirm } = useConfirm();

     const ok = await confirm({
         title: 'Konfirmasi Persetujuan',
         message: 'Apakah Anda yakin ingin menyetujui dokumen ini?',
         variant: 'primary',
     });
     if (ok) {
         // Lanjutkan eksekusi
     }
     ```
3. **`useToast`**:
   - Digunakan untuk menampilkan pesan notifikasi toast imperatif:
     ```js
     import { useToast } from '@/composables/useToast';
     const { addToast } = useToast();

     addToast({ variant: 'success', title: 'Berhasil', message: 'Data tersimpan.' });
     ```
4. **`useListQuery`**:
   - Digunakan untuk mengelola sinkronisasi filter pencarian, pengurutan, pagination, dan status loading ke URL browser dengan debounce otomatis 300ms.
5. **`useTheme`**:
   - Digunakan untuk toggle atau membaca tema aktif (`light` vs `dark`).
6. **`useFormatters`**:
   - Berisi fungsi format aman float: `formatRupiah`, `formatKuantitas`, `formatTanggal`, `formatPersen`.

---

## 4. Larangan Mutlak & Kepatuhan Keamanan

| Larangan | Alasan Keamanan / Kualitas | Solusi Pengganti |
|---|---|---|
| **DILARANG `v-html`** | Menghindari celah XSS (*Cross-Site Scripting*) | Gunakan slot Vue biasa atau binding teks standar `{{ text }}` |
| **DILARANG `style="..."` statis** | Menegakkan kepatuhan CSP (*Content Security Policy*) tanpa `'unsafe-inline'` | Gunakan kelas utilitas Tailwind CSS |
| **DILARANG tag `<style>` di SFC** | Menjaga keseragaman token tema dan menghindari injeksi CSS runtime | Konfigurasikan token di `app.css` dan gunakan utilitas Tailwind |
| **DILARANG CDN Pihak Ketiga** | Menjamin integritas rantai pasokan (*supply chain security*) dan ketersediaan offline/internal | Semua aset (font Inter, ikon Lucide, pustaka) di-*self-host* dan di-bundle oleh Vite |
| **DILARANG TypeScript (`.ts`)** | Mematuhi spesifikasi fondasi proyek (JavaScript ECMAScript murni) | Tulis JavaScript modern dengan strict prop types pada komponen |

---

## 5. Panduan Pemformatan Angka & Tanggal Indonesia (Strict Float-Safe)

### A. Format Mata Uang Rupiah
Operasi aritmetika dan konversi menggunakan `parseFloat()` atau `Number()` **DILARANG KERAS** pada data mata uang karena risiko kehilangan presisi IEEE-754.

Gunakan komponen `<Rupiah :value="amount" />` atau fungsi `formatRupiah(amount)` dari `useFormatters`:
- Input berupa representasi string angka presisi tetap dari backend (`"15450000.0000"`).
- Diformat menggunakan `Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' })` berbasis pemisahan string BigInt.
- Nilai nol diformat menjadi `"Rp 0"`.
- Nilai null/undefined diformat menjadi fallback `"-"`.

### B. Format Kuantitas Desimal
Gunakan komponen `<Quantity :value="qty" :decimals="2" />` atau fungsi `formatKuantitas(qty, decimals)`:
- Menghasilkan format angka Indonesia (pemisah ribuan titik, pemisah desimal koma).
- Contoh: `"1250.7500"` diformat menjadi `"1.250,75"`.

### C. Format Tanggal & Waktu
Gunakan komponen `<DateDisplay :value="timestamp" :with-time="true" />` atau fungsi `formatTanggal(timestamp, withTime)`:
- Format tanggal standar: `DD MMMM YYYY` (contoh: `28 Maret 2026`).
- Format dengan waktu: `DD MMMM YYYY, HH:mm WIB` (contoh: `28 Maret 2026, 14:30 WIB`).
