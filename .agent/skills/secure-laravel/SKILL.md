---
name: secure-laravel
description: Gunakan saat menulis atau mengubah kode Laravel/Inertia (controller, request, service, query, autentikasi, otorisasi, upload, konfigurasi). Aturan coding aman terhadap RCE, injeksi, dan kebocoran data.
---
# Aturan Coding Aman (Laravel 13 + Inertia)

## Struktur
Route -> Controller tipis -> FormRequest -> Service (logika bisnis, DB::transaction) -> Model. Tanpa query di Controller, Blade, atau Vue.

## Wajib
- Validasi semua input lewat FormRequest dengan aturan eksplisit; batasi panjang maksimum.
- Otorisasi di setiap aksi (Gate/Policy/middleware). Default-nya TOLAK. Jangan percaya `role`/`is_admin`/`user_id` dari input.
- Model: `$fillable` eksplisit; dilarang `$guarded = []` dan `Model::unguard()`; `$hidden` untuk password/token.
- Query: Eloquent atau query builder dengan binding. Raw SQL hanya literal statis.
- Multi-tabel dibungkus `DB::transaction`; stok dan penomoran dokumen memakai `lockForUpdate`.
- Autentikasi: pesan error generik (tanpa membocorkan akun ada atau tidak), rate limit, regenerasi sesi saat login, invalidasi sesi saat logout, tanpa remember-me, hash argon2id.
- Respons error tanpa stack trace, path, atau SQL. Jangan log atau kirim ke klien: password, token, isi .env.
- Redirect hanya ke URL internal yang divalidasi.

## Dilarang
eval, exec, shell_exec, system, passthru, proc_open, popen, unserialize, assert, extract, backtick, dd/dump/var_dump, facade Process, include/require dengan path dari input, request HTTP ke URL dari input user.

## Upload (bila diminta fase)
Whitelist ekstensi DAN MIME, nama file diacak, disk privat di luar `public`, unduh lewat route terotorisasi, batasi ukuran, jangan pernah dieksekusi.

## Setelah menulis kode
`make check` dan tes terkait; tambahkan tes untuk kasus gagal dan otorisasi.

## Pola Service (disepakati pemilik)
1. Master data sederhana: Service tipis (satu method per aksi, tanpa abstraksi generik).
2. Dokumen, stok, dan keuangan: Service orkestrasi (urutan langkah, DB::transaction, penguncian, audit) + kelas aturan murni tanpa database (Decimal, state machine, kalkulator). Logika bisnis DILARANG di Controller dan Model.
3. DILARANG Service yang hanya membungkus satu panggilan Model tanpa alasan (transaksi, audit, atau penjagaan aturan). Query baca kompleks: scope di Model atau kelas query khusus.

## Pola Service (disepakati pemilik)
1. Master data sederhana: Service tipis (satu method per aksi, tanpa abstraksi generik).
2. Dokumen, stok, dan keuangan: Service orkestrasi (urutan langkah, DB::transaction, penguncian, audit) + kelas aturan murni tanpa database (Decimal, state machine, kalkulator). Logika bisnis DILARANG di Controller dan Model.
3. DILARANG Service yang hanya membungkus satu panggilan Model tanpa alasan (transaksi, audit, atau penjagaan aturan). Query baca kompleks: scope di Model atau kelas query khusus.

## Pola Service (disepakati pemilik)
1. Master data sederhana: Service tipis (satu method per aksi, tanpa abstraksi generik).
2. Dokumen, stok, dan keuangan: Service orkestrasi (urutan langkah, DB::transaction, penguncian, audit) + kelas aturan murni tanpa database (Decimal, state machine, kalkulator). Logika bisnis DILARANG di Controller dan Model.
3. DILARANG Service yang hanya membungkus satu panggilan Model tanpa alasan (transaksi, audit, atau penjagaan aturan). Query baca kompleks: scope di Model atau kelas query khusus.
