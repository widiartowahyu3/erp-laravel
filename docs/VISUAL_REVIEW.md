# Panduan Pemeriksaan Visual (Visual Review Guide)

Dokumen ini disusun untuk pemilik proyek (*product owner* / supervisor) guna memandu verifikasi visual antarmuka pengguna pada Fase 5 (Design System Frontend) melalui browser web lokal.

---

## 1. Persiapan Lingkungan

Pastikan aplikasi berjalan di lingkungan lokal:
```bash
# Di direktori ~/projects/erp-trading
docker compose up -d
```
Akses aplikasi melalui peramban: `http://127.0.0.1:8080/`

---

## 2. Checklist Verifikasi Halaman & Komponen

### A. Halaman Masuk (*Login*) — `http://127.0.0.1:8080/login`
- [ ] **Mode Terang & Mode Gelap**: Latar belakang, kartu login (`Card.vue`), teks, dan input menyesuaikan tema dengan kontras tinggi.
- [ ] **Input & Focus State**:
  - Klik pada input email & password: cincin fokus berwarna indigo terlihat jelas tanpa terpotong.
  - Tombol tampilkan/sembunyikan kata sandi (ikon mata) dapat diklik dan mengubah tipe input teks/password.
- [ ] **State Validasi & Error**:
  - Coba kirim form kosong: pesan validasi field muncul di bawah input dengan warna teks merah dan ikon peringatan.
  - Coba login salah 5 kali: banner alert pembatasan laju (*rate limiting*) muncul dengan latar belakang merah muda dan pesan informatif.
- [ ] **Responsivitas**: Tampilan terpusat dengan rapi pada resolusi 375px (mobile), 768px (tablet), dan 1280px+ (desktop).

### B. Halaman Ubah Kata Sandi — `http://127.0.0.1:8080/account/password`
*(Memerlukan login terlebih dahulu)*
- [ ] **Struktur Form**:
  - Tiga input kata sandi (Kata Sandi Saat Ini, Kata Sandi Baru, Konfirmasi Kata Sandi Baru) terpasang rapi di dalam `Card.vue`.
  - Masing-masing field memiliki tombol toggle visibilitas mandiri.
- [ ] **Kotak Kebijakan Kata Sandi**:
  - Kotak informasi persyaratan kata sandi (12 karakter, huruf besar, huruf kecil, angka, dll.) terbaca dengan jelas.
- [ ] **Tombol Aksi**:
  - Tombol simpan memiliki ikon kunci dan menampilkan spinner saat form sedang diproses.

### C. Halaman Dashboard — `http://127.0.0.1:8080/`
- [ ] **Header Halaman**:
  - Menampilkan nama pengguna, email, badge status "Sistem Aktif" (dengan indikator hijau berdenyut), dan badge "Security Hardened".
- [ ] **Kisi Kartu Modul Bisnis**:
  - Modul yang memiliki izin akses tampil dengan kartu aktif, ikon berwarna indigo, dan teks status "Izin Diberikan".
  - Modul yang belum diizinkan tampil dalam status terkunci (*disabled/opacity*) dengan badge "Terkunci".
- [ ] **Status Arsitektur Sistem**:
  - Tiga panel ringkasan informasi arsitektur (Backend Laravel 13, Frontend Vue 3 + Tailwind 4, Basis Data MariaDB 12.3 LTS) tampil simetris di bagian bawah.

### D. Halaman Katalog Styleguide Internal — `http://127.0.0.1:8080/dev/styleguide`
*(Hanya dapat diakses pada `APP_ENV=local` dengan pengguna terautentikasi)*
- [ ] **1. Palet Token Warna**: Swatches token (Primary, Success, Warning, Danger, Info, Surface) tampil akurat di mode terang dan gelap.
- [ ] **2. Tipografi**: Skala Heading 1 hingga Heading 4 serta perataan angka tabular pada teks desimal.
- [ ] **3. Varian & Ukuran Tombol**:
  - Tombol Primary, Secondary, Danger, Ghost.
  - Ukuran sm, md, lg.
  - Status normal, loading (dengan spinner berputar), dan disabled.
  - Icon buttons dengan `aria-label`.
- [ ] **4. Form Controls**:
  - TextInput, PasswordInput, Textarea (auto-resize), Select, Checkbox, dan Switch.
  - Integrasi validasi pesan error dan hint di bawah field.
- [ ] **5. Feedback & Status**:
  - 4 varian Alert (info, success, warning, danger).
  - 15 StatusBadge dokumen bisnis (draft, approved, rejected, void, processing, paid, overdue, dll).
  - Spinner, Skeleton, dan Tooltip aksesibel (arahkan kursor ke tombol tooltip).
  - Uji tombol Toast notifikasi: klik untuk memicu toast sukses, galat, peringatan, dan info di pojok kanan bawah. Arahkan mouse ke toast untuk menjeda hitung mundur durasi.
- [ ] **6. Dialog Modal & Konfirmasi**:
  - Klik "Buka Modal Contoh": verifikasi dialog muncul di tengah layar, scroll body terkunci, tekan `Escape` untuk menutup.
  - Klik "Uji Dialog Konfirmasi Primary": dialog konfirmasi muncul dengan tombol 'Ya, Setujui' dan 'Batal'.
  - Klik "Uji Dialog Konfirmasi Bahaya": dialog konfirmasi merah muncul mewajibkan pengisian alasan pembatalan dokumen.
- [ ] **7. Formatter Nilai (Aman Float)**:
  - Rupiah (`Rp 125.500.000`), Kuantitas (`1.250,7500`), Persentase (`11,00%`), dan Tanggal Waktu Indonesia (`28 Maret 2026, 14:30 WIB`).
- [ ] **8. Data Table & Pagination**:
  - Tabel 5 baris dengan header sticky.
  - Klik header kolom (misal 'Tanggal', 'Total Nilai') untuk menguji sorting interaktif (tanda panah naik/turun).
  - Input kata kunci di FilterBar: data otomatis tersaring secara instan tanpa reload halaman.
  - Komponen pagination di bawah tabel.
- [ ] **9. State Views**:
  - Tampilan EmptyState (data kosong), ErrorState (pesan galat sistem dengan tombol coba lagi), dan LoadingState.

---

## 3. Checklist Uji Responsivitas & Viewport

Gunakan Developer Tools pada browser (mode emulasi perangkat):
1. **Viewport Mobile (375px - misal iPhone SE / Galaxy)**:
   - Sidebar desktop tersembunyi secara otomatis.
   - Header mobile muncul dengan tombol hamburger menu.
   - Klik hamburger menu: Drawer samping meluncur mulus dari kiri, backdrop gelap muncul di latar.
   - Tekan `Escape`: Drawer menutup dan fokus kembali ke tombol hamburger.
   - Form dan tombol memenuhi lebar kontainer (*full width*) dengan margin sentuh aman.
2. **Viewport Tablet (768px - misal iPad)**:
   - Tata letak form berubah menjadi 2 kolom responsif.
   - Grid kartu modul menyesuaikan menjadi 2 kolom.
3. **Viewport Desktop (1280px+ - misal Laptop / Monitor)**:
   - Sidebar desktop menetap di sisi kiri.
   - Klik tombol ciutkan sidebar (panah kiri di bawah atau panel toggle di topbar): sidebar menyusut menjadi ikon saja (lebar 64px) dengan transisi mulus.
   - Status ciut (*collapsed*) tersimpan di `localStorage` dan bertahan saat halaman di-refresh.
   - Klik menu dropdown pengguna di pojok kanan atas: menu terbuka dengan opsi "Ubah Kata Sandi" dan "Keluar".

---

## 4. Checklist Uji Aksesibilitas Keyboard (Tanpa Mouse)

1. Muat halaman `/dev/styleguide`.
2. Tekan tombol `Tab` pertama kali: tautan *"Lewati ke konten"* (*skip link*) muncul di pojok kiri atas. Tekan `Enter`, fokus langsung melompat ke konten `<main>`.
3. Telusuri form menggunakan `Tab` dan `Shift+Tab`: urutan fokus mengalir secara logis dari atas ke bawah, kiri ke kanan.
4. Buka Modal: tekan `Tab` berulang kali, fokus berputar hanya di dalam modal (*focus trap* aktif). Tekan `Escape`, modal tertutup dan fokus kembali ke tombol pemicu.
