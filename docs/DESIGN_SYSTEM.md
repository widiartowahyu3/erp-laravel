# Dokumentasi Design System ERP Distribusi/Trading

Dokumen ini mendokumentasikan spesifikasi token desain, palet warna, tipografi, tema terang/gelap, serta standar aksesibilitas pada frontend aplikasi ERP Distribusi/Trading berbasis Vue 3, Inertia 3, dan Tailwind CSS 4.

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
