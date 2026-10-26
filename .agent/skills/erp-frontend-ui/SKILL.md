---
name: erp-frontend-ui
description: Gunakan saat membuat atau mengubah halaman, komponen, layout, atau gaya di frontend Vue 3 + Inertia + Tailwind 4 proyek ERP. Aturan desain, UX, aksesibilitas, dan keamanan CSP agar tampilan konsisten dan tidak asal.
---
# Aturan Frontend ERP (Vue 3 + Inertia + Tailwind 4)

## Desain
- Pakai token di `resources/css/app.css` (`@theme`: font Inter, primary indigo, netral slate, success emerald, warning amber, danger rose, info sky, radius 0.5rem, grid 4px). DILARANG warna, ukuran, atau font di luar token.
- Ikon hanya `lucide-vue-next`. Dilarang menambah library UI/CSS tanpa izin tertulis pemilik.
- Rakit halaman dari komponen yang sudah ada di `Components/ui` dan `Components/domain`. Jangan membuat komponen UI baru bila yang serupa sudah ada; jika perlu, usulkan dulu.

## Pola baku
- Tabel data: filter, sort, pagination, aksi per baris, scroll horizontal di dalam wadahnya sendiri.
- Form: label terlihat, pesan error dari server di bawah field (terhubung via aria-describedby), tombol dinonaktifkan saat `processing`, atribut `autocomplete` yang benar, fokus ke error pertama.
- Badge status dokumen dengan warna tetap: draft (netral), approved (info), posted (success), partially_* (warning), void (danger).
- Aksi destruktif/void selalu lewat modal konfirmasi dan meminta alasan bila perlu.
- Setiap halaman data wajib menangani empat state: memuat, kosong, galat, dan tanpa izin.

## Format dan bahasa
Semua teks UI Bahasa Indonesia. Rupiah, angka, dan tanggal memakai `Intl` locale `id-ID`; jangan memformat manual.

## Aksesibilitas dan responsif
Kontras WCAG AA, fokus terlihat, bisa dipakai dengan keyboard, label dan aria benar, target sentuh minimal 40px, hormati prefers-reduced-motion. Uji pada lebar HP (375px), tablet (768px), dan desktop (1280px).

## CSP dan keamanan
Tanpa atribut `style=`, tanpa tag `<style>` inline, tanpa `v-html`, tanpa CDN atau font eksternal, tanpa skrip inline. Gaya lewat kelas Tailwind dan CSS file.

## Konvensi Vue
`<script setup>` + Composition API; halaman di `Pages/<Modul>/`; form lewat `useForm` Inertia; tanpa logika bisnis di komponen; props bertipe dan emits dideklarasikan.

## Bukti saat melapor
Sebutkan komponen yang dipakai, state yang ditangani, dan hasil `npm run lint` + `npm run build`. Pemilik akan mengirim screenshot untuk ditinjau pengawas.
