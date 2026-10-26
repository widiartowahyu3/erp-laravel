---
name: erp-phase-protocol
description: Gunakan di awal dan akhir setiap fase atau tugas proyek ERP. Protokol kerja berfase, kapan berhenti dan bertanya, dan cara menyusun laporan yang diverifikasi pengawas.
---
# Protokol Kerja Berfase

## Sebelum mulai
1. Baca seluruh prompt fase dan daftar semua langkahnya.
2. `git status` harus bersih. Jalankan `make verify` (bila stack hidup) dan simpan baseline ke review/baseline.txt.
3. Lakukan pengecekan versi bila diminta (composer outdated --direct, npm outdated), simpan ke review/.

## Selama bekerja
- Berurutan; satu commit per langkah.
- BERHENTI dan tanya bila: spesifikasi ambigu, perlu tabel/kolom/paket di luar spesifikasi, hasil bertentangan dengan prompt, atau tindakan berisiko (destruktif, di luar folder proyek, menyentuh keamanan).
- Tes gagal: perbaiki akar masalahnya. Jangan melonggarkan tes, aturan, atau skrip verifikasi.
- Tes yang menguji kegagalan harus mengecek PENYEBAB yang tepat (nama constraint/kode error), bukan sekadar "ada exception", dan disertai kontrol positif.

## Sebelum melapor
Jalankan semua gerbang dan simpan keluaran mentah ke review/ (lihat AGENTS.md bagian Laporan).

## Isi laporan
1. Daftar perintah + nama berkas keluarannya.
2. Bukti yang diminta prompt fase.
3. Daftar keputusan sendiri, penyimpangan, dan bagian yang dilewati atau dipersempit.
4. Perintah push untuk pemilik (tanpa --force). Jangan push sendiri.
