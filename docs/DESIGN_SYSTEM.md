# Dokumentasi Design System ERP Distribusi/Trading

Dokumen ini mendokumentasikan spesifikasi token desain, palet warna, tipografi, tema terang/gelap, katalog komponen UI, standar aksesibilitas, dan checklist pembuatan komponen baru pada frontend aplikasi ERP Distribusi/Trading berbasis Vue 3, Inertia 3, dan Tailwind CSS 4.

---

## 1. Arsitektur Token Semantik

Sistem desain menggunakan pendekatan token semantik lapis ganda:
1. **At-Theme Tokens (`@theme` di `resources/css/app.css`)**: Mendefinisikan font sans, skala radius border, durasi transisi, dan skala warna dasar.
2. **CSS Custom Properties Semantik (`:root` / `[data-theme="light"]` vs `[data-theme="dark"]`)**: Menyediakan variabel CSS fungsional yang berubah otomatis sesuai tema aktif tanpa memicu *layout thrashing* atau injeksi CSS dinamis.

### A. Token Permukaan & Latar (*Surfaces*)
| Token CSS | Mode Terang (Light) | Mode Gelap (Dark) | Peruntukan |
|---|---|---|---|
| `--surface-base` | `#f8fafc` (slate-50) | `#0b0f19` (slate-950 deep) | Latar belakang kanvas utama aplikasi |
| `--surface-card` | `#ffffff` (white) | `#111827` (slate-900 / gray-900) | Kartu, kontainer form, tabel data, sidebar |
| `--surface-muted` | `#f1f5f9` (slate-100) | `#1e293b` (slate-800) | Header tabel, background field disabled, badge default |
| `--surface-popover`| `#ffffff` | `#1f2937` (slate-800 deep) | Dropdown menu, tooltip, popover |

### B. Token Garis Batas (*Borders*)
| Token CSS | Mode Terang | Mode Gelap | Peruntukan |
|---|---|---|---|
| `--border-subtle` | `#e2e8f0` (slate-200) | `#334155` (slate-700) | Pembatas baris tabel, sekat card, divider |
| `--border-strong` | `#cbd5e1` (slate-300) | `#475569` (slate-600) | Border input form, batas panel aktif |

### C. Token Tipografi & Teks (*Text Hierarchy*)
| Token CSS | Mode Terang | Mode Gelap | Rasio Kontras WCAG AA |
|---|---|---|---|
| `--text-main` | `#0f172a` (slate-900) | `#f8fafc` (slate-50) | >= 17:1 (Target: >= 4.5:1) |
| `--text-muted` | `#475569` (slate-600) | `#94a3b8` (slate-400) | >= 6.9:1 (Target: >= 4.5:1) |
| `--text-subtle`| `#64748b` (slate-500) | `#64748b` (slate-500) | Teks keterangan sekunder / placeholder |
| `--text-inverse`| `#ffffff` | `#0f172a` | Teks di atas latar belakang kontras |

### D. Token Aksi Utama (*Primary Action*)
| Token CSS | Mode Terang | Mode Gelap | Keterangan |
|---|---|---|---|
| `--action-primary` | `#4f46e5` (indigo-600) | `#4f46e5` (indigo-600) | Tombol CTA utama, status aktif nav |
| `--action-primary-hover` | `#4338ca` (indigo-700) | `#4338ca` (indigo-700) | State hover interaktif |
| `--action-primary-active`| `#3730a3` (indigo-800) | `#3730a3` (indigo-800) | State pressed / active |
| `--action-primary-text`  | `#ffffff` | `#ffffff` | Teks tombol primer (Kontras 6.29:1) |
| `--ring-focus` | `#6366f1` (indigo-500) | `#818cf8` (indigo-400) | Cincin fokus aksesibilitas keyboard |

### E. Token Semantik Status Dokumen & Alert
| Status | Mode Terang (Bg / Border / Text) | Mode Gelap (Bg / Border / Text) | Rasio Kontras Teks:Bg |
|---|---|---|---|
| **Success** | `#ecfdf5` / `#a7f3d0` / `#065f46` | `#022c22` / `#047857` / `#6ee7b7` | >= 7.2:1 (Light), >= 9.9:1 (Dark) |
| **Warning** | `#fffbeb` / `#fde68a` / `#92400e` | `#451a03` / `#b45309` / `#fcd34d` | >= 6.8:1 (Light), >= 10.3:1 (Dark)|
| **Danger**  | `#fff1f2` / `#fecdd3` / `#9f1239` | `#4c0519` / `#be123c` / `#fda4af` | >= 7.3:1 (Light), >= 8.2:1 (Dark) |
| **Info**    | `#f0f9ff` / `#bae6fd` / `#075985` | `#082f49` / `#0369a1` / `#7dd3fc` | >= 7.0:1 (Light), >= 8.3:1 (Dark) |

---

## 2. Tipografi & Grid Spacing

1. **Font Family**:
   - Font utama menggunakan `@fontsource-variable/inter` yang dilayani secara mandiri (*self-hosted* lokal, 100% bebas dari CDN pihak ketiga).
   - Variabel Tailwind 4: `--font-sans: 'Inter Variable', ui-sans-serif, system-ui, ...`.
2. **Grid Spacing (Kelipatan 4px)**:
   - Margin, padding, gap, dan dimensi form dirancang dalam kelipatan 4px (`0.25rem = 4px`, `0.5rem = 8px`, `0.75rem = 12px`, `1rem = 16px`, `1.25rem = 20px`, `1.5rem = 24px`, dst).
3. **Target Sentuh Minimum**:
   - Seluruh elemen kontrol interaktif (tombol, input, checkbox, tautan aksi) memiliki area sentuh minimum 40px (atau `min-h-[40px]`) sesuai standar aksesibilitas WCAG 2.1 AA.

---

## 3. Implementasi Tema Terang & Gelap

1. **Mekanisme Selektor Tema**:
   - Tema diaktifkan melalui atribut `data-theme="light"|"dark"` pada elemen root `<html>` serta kelas pendukung `.dark`.
   - Varian Tailwind dikonfigurasi melalui:
     `@custom-variant dark (&:where([data-theme="dark"], [data-theme="dark"] *, .dark, .dark *));`
2. **Deteksi Otomatis & Persistensi**:
   - Prioritas deteksi tema:
     1. Nilai yang tersimpan di `localStorage.getItem('theme')`.
     2. Preferensi sistem operasi pengguna: `window.matchMedia('(prefers-color-scheme: dark)').matches`.
     3. *Fallback default*: mode terang (`light`).
   - Seluruh akses ke `localStorage` dibungkus dalam blok `try...catch` untuk menjamin aplikasi tidak mengalami galat pada mode penjelajahan pribadi (*incognito*) atau lingkungan iframe terisolasi.
3. **Pencegahan Kedipan (*Anti-FOUC*)**:
   - Skrip pembaca tema dieksekusi secara sinkron di `<head>` pada file `resources/views/app.blade.php`.
   - Skrip inline diberi otorisasi keamanan CSP yang valid menggunakan nonce per-request: `<script nonce="{{ Vite::cspNonce() }}">`.
4. **Penghormatan Terhadap Pengurangan Gerak (*Prefers Reduced Motion*)**:
   - Ketika pengguna mengaktifkan preferensi sistem *reduced motion*, seluruh durasi animasi dan transisi CSS dinonaktifkan (`animation-duration: 0.01ms`, `transition-duration: 0.01ms`, `scroll-behavior: auto`).

---

## 4. Pengujian Otomatis Kepatuhan Kontras WCAG AA

Setiap pasangan warna teks dan latar belakang diuji secara terprogram melalui Vitest (`resources/js/test/theme-contrast.test.js`) dengan menghitung *relative luminance* dan *contrast ratio* matematis:
$$\text{Contrast Ratio} = \frac{L_1 + 0.05}{L_2 + 0.05}$$
Seluruh token teks standar wajib menghasilkan rasio $\ge 4.5:1$, dan teks besar/tebal $\ge 3.0:1$.

---

## 5. Katalog Komponen Primitif

Lokasi: `resources/js/Components/ui/`

| Komponen | Props Utama | Emits | Slots | Aksesibilitas |
|---|---|---|---|---|
| `Button.vue` | `variant` ('primary'\|'secondary'\|'danger'\|'ghost'), `size` ('sm'\|'md'\|'lg'), `type`, `loading`, `disabled`, `block` | `click` | `default`, `icon` | Focus ring, `aria-disabled`, spinner with `role="status"` |
| `IconButton.vue` | `ariaLabel` (wajib), `variant`, `size`, `type`, `disabled`, `loading` | `click` | `default` | Mandatory `aria-label`, min target 40px |
| `TextInput.vue` | `modelValue`, `type`, `id`, `name`, `placeholder`, `disabled`, `readonly`, `required`, `invalid`, `describedBy` | `update:modelValue` | - | `aria-invalid`, `aria-describedby` |
| `PasswordInput.vue` | `modelValue`, `id`, `name`, `placeholder`, `disabled`, `required`, `invalid`, `describedBy` | `update:modelValue` | - | Toggle visibility button with `aria-pressed` |
| `Textarea.vue` | `modelValue`, `id`, `rows` (3), `disabled`, `invalid`, `describedBy` | `update:modelValue` | - | CSS `field-sizing-content` auto-resize tanpa inline style |
| `Select.vue` | `modelValue`, `options` (array of {value, label}), `placeholder`, `disabled`, `invalid`, `describedBy` | `update:modelValue` | - | Keyboard accessible native select wrapper |
| `Checkbox.vue` | `modelValue`, `id`, `label`, `description`, `disabled` | `update:modelValue` | - | Custom SVG checkmark, label click connection |
| `Switch.vue` | `modelValue`, `id`, `label`, `description`, `disabled` | `update:modelValue` | - | `role="switch"`, `aria-checked` |
| `FormField.vue` | `label`, `forId`, `required`, `error`, `hint` | - | `default({ id, invalid, describedBy })` | Auto wiring id & `aria-describedby` ke error & hint |
| `Badge.vue` | `variant` ('neutral'\|'info'\|'success'\|'warning'\|'danger'), `size` ('sm'\|'md') | - | `default` | High contrast text vs background |
| `StatusBadge.vue` | `status` (string status enum dokumen) | - | - | Auto map status Fase 4 ke label Bahasa Indonesia |
| `Card.vue` | `padding` ('none'\|'sm'\|'md'\|'lg') | - | `default`, `header`, `footer` | Surface card background, border subtle |
| `Alert.vue` | `variant` ('info'\|'success'\|'warning'\|'danger'), `title`, `dismissible` | `dismiss` | `default` | `role="alert"` (danger/warning) / `role="status"` (info/success) |
| `Spinner.vue` | `size` ('sm'\|'md'\|'lg'), `variant` ('primary'\|'neutral'\|'white') | - | - | `role="status"`, `sr-only` text |
| `Skeleton.vue` | `variant` ('text'\|'rect'\|'circle'), `class` | - | - | `aria-hidden="true"`, subtle pulse animation |
| `Tooltip.vue` | `text`, `position` ('top'\|'bottom'\|'left'\|'right') | - | `default` | `role="tooltip"`, `aria-describedby` on trigger |

---

## 6. Katalog Komponen Komposit

Lokasi: `resources/js/Components/ui/`

| Komponen | Props Utama | Emits | Slots | Aksesibilitas |
|---|---|---|---|---|
| `Modal.vue` | `show`, `title`, `maxWidth` ('sm'\|'md'\|'lg'\|'xl'\|'2xl'), `closeable` | `close` | `default`, `footer` | WAI-ARIA Dialog pattern, focus trap, body scroll lock, Escape key |
| `ConfirmDialog.vue` | `show`, `title`, `message`, `confirmText`, `cancelText`, `variant` ('primary'\|'danger'), `loading`, `requiresReason`, `reasonLabel` | `confirm(reason)`, `cancel`, `close` | - | Terintegrasi dengan `useConfirm()`, keyboard trap |
| `Toast.vue` | `id`, `variant`, `title`, `message`, `duration` | `dismiss` | - | `role="status"`, pause on mouseenter & focusin |
| `FlashRegion.vue` | - | - | - | `aria-live="polite"`, terhubung dengan session flash Inertia |
| `Breadcrumbs.vue` | `items` (array of {label, href}) | - | - | `nav[aria-label="Breadcrumb"]`, `aria-current="page"` |
| `PageHeader.vue` | `title`, `description` | - | `title`, `description`, `breadcrumbs`, `actions` | Semantic heading hierarchy |
| `EmptyState.vue` | `title`, `description` | - | `icon`, `actions` | Centered layout, clear CTA container |
| `ErrorState.vue` | `title`, `message`, `retryText` | `retry` | `icon`, `actions` | `role="alert"`, recovery action button |
| `LoadingState.vue`| `message`, `size` | - | - | Centered spinner with `role="status"` |
| `Dropdown.vue` | `align` ('left'\|'right'), `width` ('48'\|'56'\|'64'\|'auto') | - | `trigger({ open, toggle, close })`, `default({ close })` | Full keyboard navigation (Arrow Up/Down, Esc, Tab, outside click) |
| `DataTable.vue` | `columns`, `items`, `keyField`, `sortField`, `sortDirection`, `loading`, `emptyTitle`, `emptyDescription` | `sort(key)` | `cell(key)`, `empty` | Sticky header, internal overflow horizontal, sort indicators |
| `Pagination.vue` | `meta` ({current_page, per_page, total, last_page, from, to}), `perPageOptions` | `page-change`, `per-page-change` | - | Accessible buttons with `aria-label` |
| `FilterBar.vue` | `modelValue`, `placeholder`, `hasActiveFilters` | `update:modelValue`, `search`, `reset` | `filters` | Debounced search input (300ms), clear button |
| `Rupiah.vue` | `value` (string\|number), `fallback` ('-') | - | - | Strict float safe formatting `Intl.NumberFormat('id-ID')` |
| `Quantity.vue` | `value` (string\|number), `decimals` (2), `fallback` ('-') | - | - | Strict float safe formatting `Intl.NumberFormat('id-ID')` |
| `DateDisplay.vue`| `value` (string), `withTime` (false), `fallback` ('-') | - | - | Localized Indonesian date string |
| `PercentDisplay.vue`| `value` (string\|number), `decimals` (2), `fallback` ('-') | - | - | Localized percentage with `%` suffix |

---

## 7. Pola Form & Detail Dokumen

### A. Pola Standar Form Input
Setiap input dalam form wajib dibungkus dengan `FormField` untuk menjamin pemasangan label, atribut `aria-describedby`, dan penandaan error secara otomatis:
```vue
<FormField label="Nama Pelanggan" :error="form.errors.customer_name" required>
    <template #default="{ id, invalid, describedBy }">
        <TextInput
            :id="id"
            v-model="form.customer_name"
            :invalid="invalid"
            :described-by="describedBy"
            required
        />
    </template>
</FormField>
```

### B. Pola Konfirmasi Tindakan Berbahaya
Tindakan pembatalan (*void*), penolakan, atau penghapusan data wajib menggunakan `useConfirm` dengan varian `danger` dan meminta input alasan pembatalan:
```js
const { confirm } = useConfirm();

async function handleVoid(documentId) {
    const result = await confirm({
        title: 'Batalkan (Void) Dokumen Faktur',
        message: 'Tindakan ini tidak dapat diurungkan dan akan membalikkan entri jurnal terkait.',
        confirmText: 'Ya, Batalkan Dokumen',
        variant: 'danger',
        requiresReason: true,
        reasonLabel: 'Alasan Pembatalan Dokumen',
    });

    if (result && result.confirmed) {
        router.post(`/invoices/${documentId}/void`, { reason: result.reason });
    }
}
```

---

## 8. Standar Aksesibilitas Frontend

1. **Navigasi Keyboard**: Seluruh elemen interaktif dapat dijangkau dan dioperasikan hanya menggunakan tombol `Tab`, `Shift+Tab`, `Enter`, `Space`, dan tombol panah (`Arrow Keys`).
2. **Focus Management**:
   - Modal dan Drawer mobile mengaktifkan *focus trap* saat terbuka sehingga fokus tidak keluar dari konteks dialog.
   - Saat ditutup, fokus dikembalikan ke elemen pemicu (*trigger element*).
3. **Pemberitahuan Screen Reader**:
   - Pesan toast dan feedback sistem menggunakan atribut `role="status"` atau `role="alert"` dengan `aria-live="polite"` agar dibacakan screen reader tanpa memutus fokus pengguna.
4. **Tanpa Inline Style**:
   - Seluruh penataan tampilan wajib menggunakan utilitas Tailwind CSS.
   - Dilarang keras menggunakan atribut `style="..."` statis atau tag `<style>` di SFC demi kepatuhan CSP (*Content Security Policy*) tanpa `unsafe-inline`.
5. **Keamanan Angka Desimal**:
   - Dilarang menggunakan `parseFloat()` atau `Number()` pada nilai mata uang dan kuantitas inventori. Semua pemformatan angka menggunakan BigInt string splitting via `useFormatters` atau komponen formatter `Rupiah` dan `Quantity`.

---

## 9. Checklist Sebelum Membuat Komponen Baru

Sebelum menambahkan komponen baru ke dalam repositori, lakukan checklist berikut:
- [ ] Komponen menggunakan `<script setup>` murni (tanpa TypeScript).
- [ ] Tidak menggunakan `v-html`.
- [ ] Tidak menggunakan tag `<style>` atau atribut `style="..."` statis.
- [ ] Menggunakan token warna semantik (`text-slate-900 dark:text-white`, `border-slate-200 dark:border-slate-800`, dll).
- [ ] Target sentuh minimum `min-h-[40px]` untuk elemen yang dapat diklik.
- [ ] Input memiliki label yang terhubung secara eksplisit via `id` dan `for`.
- [ ] Error message terhubung via `aria-describedby` dan `aria-invalid`.
- [ ] Lulus pengujian axe-core (`npm run test:a11y` menghasilkan 0 critical/serious violations).
- [ ] Lulus pengujian static compliance (`npm run test` menghasilkan PASS pada `static-compliance.test.js`).
