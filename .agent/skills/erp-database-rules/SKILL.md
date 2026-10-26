---
name: erp-database-rules
description: Gunakan saat membuat atau mengubah migrasi, skema, seeder, atau query yang menyentuh database proyek ERP (MariaDB 12.3).
---
# Aturan Database ERP

Sumber kebenaran: `docs/DATABASE_DESIGN.md` dan `docs/ERD.md`. Jika ada pertentangan dengan ringkasan ini, ikuti dokumen dan laporkan.

## Tipe dan constraint
- Uang `decimal(18,2)`; kuantitas `decimal(18,4)`; harga/biaya satuan `decimal(18,4)`; persentase `decimal(5,2)`. DILARANG float/double.
- PK `id` bigint unsigned. Nomor dokumen string(30) UNIQUE. Tanggal dokumen `date`.
- Semua FK `RESTRICT` kecuali pivot role_user dan permission_role (`CASCADE`).
- CHECK bernama `chk_<tabel>_<aturan>`; status `chk_<tabel>_status`. SQL statis berupa string literal.
- Satu migrasi per tabel; `down()` lengkap dan benar. Perubahan skema = migrasi baru atau perbaikan migrasi yang belum pernah di-push, disertai tes skema.

## Data
- Dokumen transaksi TIDAK dihapus: status `void` + voided_at, voided_by, void_reason. Soft delete hanya data master.
- `stock_movements` dan `audit_logs` append-only (trigger menolak UPDATE/DELETE). Koreksi = baris baru. Catatan: erp_migrator masih bisa TRUNCATE/DROP TRIGGER (akun deploy, tercatat di SECURITY.md).
- Stok berubah dalam satu transaksi dengan `SELECT ... FOR UPDATE` pada `stock_balances`; saldo tidak boleh negatif. Penomoran lewat `document_sequences` dengan row lock.
- Seeder idempoten (upsert berdasarkan kode). Jangan menebak nilai hukum (mis. tarif pajak); tanyakan ke pemilik. Dilarang seeder yang membuat user atau kredensial default.

## Hak akses
Aplikasi memakai `erp_app` (hanya DML). Migrasi memakai `erp_migrator` lewat `make migrate`; kredensialnya tidak pernah disimpan di src/.env.

## Tes skema
Tes constraint WAJIB mengecek nama constraint/kode error yang tepat dan menyertakan insert valid sebagai kontrol positif.
