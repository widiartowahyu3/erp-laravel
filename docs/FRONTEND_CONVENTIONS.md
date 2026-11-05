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
7. **`useActionGuard` (Pemeriksaan Hak Akses & Status Dokumen)**:
   - Digunakan untuk memeriksa **apa yang boleh dilakukan** (*what is allowed*): mengevaluasi apakah user memiliki permission yang sesuai DAN apakah status dokumen mengizinkan aksi tersebut berdasarkan matriks status (misal draf hanya boleh diedit/diposting/dibatalkan).
   - Peran `useActionGuard` murni deklaratif dan **TIDAK** menangani status sibuk (*busy*) atau klik ganda.
   - Mendukung domain Persediaan (`canPerformAdjustment`), Pembelian (`canPerformPurchaseOrder`, `canPerformGoodsReceipt`), dan Penjualan (`canPerformSalesOrder`, `canPerformDeliveryOrder`).
   - Helper Penjualan yang disediakan:
     - SO: `canEditSalesOrder`, `canApproveSalesOrder`, `canVoidSalesOrder`, `canCloseSalesOrder`, `canCreateDOFromSO`.
     - DO: `canEditDeliveryOrder`, `canPostDeliveryOrder`, `canVoidDeliveryOrder`.
8. **`useSubmitLock` (Pengaman Klik Ganda & Status Sibuk)**:
   - Digunakan untuk mengatur **kapan tombol boleh diklik** (*when it can be clicked*): mencegah pengiriman ganda pada mutasi HTTP dokumen (`router.post/put/delete` dan `form.post/put`) akibat klik cepat berulang (*rapid double-click*).
   - Menyediakan ref `busy` dan fungsi pembungkus `submit(sendFn, options)` dengan throttle bawaan 1000ms serta penanganan otomatis pembersihan status pada `onFinish`, `onError`, dan `onCancel`.
   - Seluruh tombol aksi dokumen wajib mengikat atribut: `:loading="busy || form.processing"`, `:disabled="busy || form.processing"`, dan `:aria-busy="busy || form.processing"`.

---

## 4. Konvensi Modul Penjualan (Sales UI Conventions)

1. **Konfigurasi Status & Hak Akses (`config/sales.js`)**:
   - `salesOrderStatusConfig`: mendefinisikan label, varian status badge, dan array `allowedActions` untuk setiap status SO (`draft`, `approved`, `partially_delivered`, `delivered`, `closed`, `void`).
   - `deliveryOrderStatusConfig`: mendefinisikan label, varian status badge, dan array `allowedActions` untuk setiap status DO (`draft`, `posted`, `void`).
2. **Kalkulasi Desimal Interaktif (`calculateSalesOrderItem`)**:
   - Perhitungan live totals baris dan ringkasan pesanan pada formulir SO mendelegasikan matematika desimal ke `src/resources/js/Support/decimal.js` berbasis BigInt.
   - Mendukung diskon baris hingga 100% tanpa risiko pembulatan floating-point JavaScript IEEE-754.
3. **Alur Navigasi & Tautan Dokumen (SO ke DO)**:
   - Pembuatan surat jalan dari pesanan penjualan wajib mengarahkan ke rute `/sales/delivery-orders/create?sales_order_id={id}` (dilarang menggunakan parameter non-baku seperti `so_id`).
   - Halaman daftar DO mengarahkan tombol "Kirim dari SO" ke `/sales/sales-orders?status=approved`.
4. **Proteksi Informasi Biaya (HPP / `unit_cost`)**:
   - Kolom dan nilai HPP (`unit_cost` dan `total_cost`) pada halaman rincian surat jalan (`DeliveryOrders/Show.vue`) **HANYA** ditampilkan jika status surat jalan sudah `posted`.
   - Pada status `draft` dan `void`, kolom biaya disembunyikan guna menjaga integritas dan kerahasiaan informasi pembiayaan.

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

---

## 6. Glosarium Istilah UI Baku

Untuk menjaga keseragaman antarmuka pengguna pada seluruh modul ERP, istilah UI dibakukan sebagai berikut:

| Istilah Baku | Istilah Terlarang / Usang | Konteks & Catatan Penggunaan |
|---|---|---|
| **Pemasok** | Mitra Pemasok, Rekanan Vendor | Digunakan pada seluruh label, tombol, placeholder, opsi pilihan, dan pesan terkait entitas pemasok (`suppliers`). |
| **Gudang** | Fasilitas Gudang, Gudang Tujuan (umum) | Digunakan pada seluruh label, filter, tabel, dan formulir lokasi penyimpanan stok (`warehouses`). Frasa "Gudang Tujuan" hanya boleh digunakan jika secara semantik merujuk pada gudang tujuan mutasi persediaan antargudang. |
| **Produk** | Barang Dagang | Digunakan pada seluruh label, katalog, tabel, rincian dokumen pengadaan, dan inventori (`products`). |

---

## 7. Standar Aksesibilitas Antarmuka (WCAG 2.1 AA)

1. **Audit Otomatis axe-core**:
   - Seluruh komponen primitif, komposit, halaman form, tabel, modal dialog, dan dashboard diuji secara otomatis menggunakan `@axe-core/playwright` / `axe-core`.
   - **Toleransi Nol**: Dilarang memiliki pelanggaran aksesibilitas berstatus `critical` maupun `serious`.
2. **Navigasi Keyboard & Manajemen Fokus**:
   - Seluruh elemen interaktif (tombol, input, dropdown, baris tabel interaktif) wajib dapat diakses penuh via keyboard (`Tab`, `Shift+Tab`, `Enter`, `Space`, `Escape`).
   - Modal dan konfirmasi dialog mengimplementasikan *focus trap* dan mengembalikan fokus ke elemen pemicu saat ditutup.
3. **Kontras Warna & Indikator Status**:
   - Rasio kontras teks terhadap latar belakang memenuhi batas minimum WCAG AA (minimal 4.5:1 untuk teks normal, 3:1 untuk teks besar dan elemen UI grafis) pada mode terang maupun gelap.
   - Status badge dan indikator kesalahan tidak hanya bergantung pada warna (selalu menyertakan label teks atau ikon penjelas).

## Helper Uang (`Support/money.js`)

Penjumlahan dan pengurangan uang di browser memakai `addMoney`, `subtractMoney`, `compareMoney`, dan `sumMoney`, yang bekerja pada string desimal dua digit dalam satuan sen `BigInt`, tanpa float. Masukan tidak valid melempar `RangeError`, jadi periksa dulu dengan `isValidMoney`. Helper ini hanya untuk indikator di layar; server tetap sumber kebenaran validasi.

## Editor Baris Jurnal

Editor baris ada di `Pages/Accounting/Journals/Form.vue`. Setiap baris hanya boleh terisi satu sisi, dan minimal dua baris selalu tersisa. Kesalahan server ditampilkan per baris lewat kunci `lines.<indeks>.<kolom>`.

## Halaman Administrasi

- `Components/admin/PermissionMatrix.vue` menampilkan matriks izin. Aturan centangnya ada di `Support/permissionMatrix.js` sebagai fungsi murni agar mudah diuji. Server tetap menegakkan aturan yang sama.
- Aksi yang diblokir aturan bisnis tetap ditampilkan dalam keadaan nonaktif dengan alasan tertulis, sedangkan aksi tanpa izin disembunyikan dengan `Can`.
- Nilai JSON log audit ditampilkan lewat interpolasi teks di dalam `<pre>`, tanpa `v-html`.

## Urutan Heading

- Satu `<h1>` dari `PageHeader`. Judul `Card`, `EmptyState`, `ErrorState`, dan `Modal` memakai `<h2>`.
- `<h3>` hanya untuk judul yang benar-benar bersarang di bawah `<h2>`.
- Tes axe halaman menyertakan aturan `heading-order`, dan `static-compliance.test.js` memeriksa judul di `#header` Card.

## Kontrak `DataTable` dan `FilterBar`

- `DataTable` menerima baris lewat `:data`. Tidak ada prop `rows` atau `empty`; keadaan kosong muncul sendiri saat `data` kosong.
- Filter tambahan `FilterBar` berada di slot bawaan, dan teks petunjuk pencarian memakai `search-placeholder`.
- `data-table-compliance.test.js` memeriksa kedua kontrak ini pada semua halaman.

## Halaman Faktur Penjualan

`Pages/Sales/Invoices/` memakai props camelCase dan nilai uang berupa string.

- **Formulir:** hanya meminta kuantitas, tanggal, jatuh tempo, dan catatan. Tidak ada input harga, diskon, atau pajak karena server yang menghitung. Baris dengan kuantitas kosong tidak dikirim, sehingga galat server per baris dicocokkan lewat `delivery_order_item_id`, bukan posisi baris.
- **Pilih pesanan:** `create` tanpa `sales_order_id` menampilkan daftar SO yang masih punya sisa tertagih, maksimal 50 baris dengan pencarian.
- **Cetak:** `Print.vue` memakai `Layouts/PrintLayout.vue` tanpa menu. Tombol memanggil `window.print()` lewat handler agar patuh CSP, dan tampilan cetak diatur dengan kelas `print:` Tailwind. Faktur draf dan dibatalkan menampilkan statusnya pada kop.
- **Label formulir:** `Textarea` membungkus elemennya dengan `div`, sehingga `aria-label` pada komponen ini jatuh ke `div`. Hubungkan labelnya lewat `id` dari slot `FormField`.
