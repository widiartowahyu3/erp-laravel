# Administrasi dan Hak Akses

Modul ini mengelola pengguna, peran beserta izinnya, dan penampil log audit. Rute ada di bawah `/admin`, masing-masing dijaga `can:` spesifik, dan seluruh aksi tulis dibatasi `throttle:erp-write`.

Aturan keamanan dipusatkan di `App\Services\Administration\AccessGuard`. Halaman hanya memperjelas aturan itu kepada pengguna; penolakan akhir selalu terjadi di server.

## 1. Rute

| Halaman atau aksi | Rute | Izin |
|---|---|---|
| Daftar dan detail pengguna | `GET /admin/users`, `GET /admin/users/{user}` | `users.view` |
| Tambah pengguna | `GET /admin/users/create`, `POST /admin/users` | `users.create` |
| Ubah pengguna dan perannya | `GET /admin/users/{user}/edit`, `PUT /admin/users/{user}` | `users.update` |
| Reset kata sandi | `POST /admin/users/{user}/reset-password` | `users.update` |
| Aktifkan atau nonaktifkan | `POST /admin/users/{user}/active` | `users.delete` |
| Daftar dan detail peran | `GET /admin/roles`, `GET /admin/roles/{role}` | `roles.view` |
| Tambah peran | `GET /admin/roles/create`, `POST /admin/roles` | `roles.create` |
| Duplikat peran | `POST /admin/roles/{role}/duplicate` | `roles.create` |
| Ubah peran | `GET /admin/roles/{role}/edit`, `PUT /admin/roles/{role}` | `roles.update` |
| Hapus peran | `DELETE /admin/roles/{role}` | `roles.delete` |
| Log audit | `GET /admin/audit-logs` | `audit_logs.view` |

## 2. Peran

- **Peran bawaan** (`is_system = true`) tidak dapat diubah atau dihapus lewat web. Sumber kebenarannya adalah seeder dan `erp:permissions:sync`. Untuk menyesuaikan, pakai **Duplikat**.
- **Peran kustom** dibuat dengan nama tampilan dan deskripsi opsional. Slug dibuat otomatis (`^[a-z][a-z0-9_]{2,49}$`) dengan sufiks angka bila bentrok.
- Peran kustom hanya dapat dihapus bila tidak ada pengguna yang memakainya.
- Himpunan izin boleh kosong. Pengguna dengan peran itu hanya melihat Dashboard.
- `erp:permissions:sync` tidak menyentuh peran kustom dan relasinya.

### Ketergantungan izin

Setiap izin non-`view` pada suatu sumber mensyaratkan izin `view` sumber yang sama, untuk sumber yang memilikinya. Server menolak himpunan yang tidak konsisten dengan galat pada kolom `permissions`.

### Matriks izin

Izin dikelompokkan per modul, sumber, lalu aksi oleh `PermissionCatalog`. Label aksi: Lihat, Tambah, Ubah, Hapus, Setujui, Posting, Batalkan, Tutup. Aksi lain memakai nama tampilan izin.

## 3. Plafon pemberian izin

Berlaku bagi semua pengguna selain Super Administrator:

- Hanya dapat menambah atau mencabut izin yang ia miliki sendiri pada peran kustom.
- Hanya dapat memberikan atau mencabut peran yang seluruh izinnya ia miliki.
- Tidak dapat melihat, mengubah, atau memberikan peran Super Administrator kepada siapa pun. Pemegang peran itu tidak muncul di daftar pengguna, halamannya menjawab 404, dan jumlah pemegangnya tidak ditampilkan.
- Tidak dapat mengelola pengguna yang memiliki izin di luar izinnya sendiri.
- Tidak dapat menduplikat atau menghapus peran yang memuat izin di luar izinnya sendiri.

Aturan pengelolaan pengguna di atas lebih ketat daripada sekadar melindungi Super Administrator, karena siapa pun yang dapat mereset kata sandi seseorang dapat mengambil alih akun itu.

**Akibat praktis:** peran bawaan `admin` tidak memiliki izin approve, post, dan void. Pemegangnya tidak dapat memberikan peran operasional seperti `purchasing`, dan tidak dapat mengelola pengguna yang memegang peran itu. Pekerjaan tersebut menjadi tugas Super Administrator.

## 4. Perlindungan akun

- Super Administrator aktif yang terakhir tidak dapat dinonaktifkan atau dicabut perannya. Pemeriksaan ini mengunci baris peran sehingga dua perubahan bersamaan tidak dapat lolos berdua.
- Pengguna tidak dapat menonaktifkan akunnya sendiri atau mengubah perannya sendiri.
- Pengguna tidak dapat mereset kata sandinya sendiri lewat halaman Pengguna, karena itu akan mengakhiri sesinya. Gunakan halaman akun.
- Pengguna tidak pernah dihapus. Izin `users.delete` berarti mengaktifkan dan menonaktifkan akun, dan menonaktifkan mencabut seluruh sesi.
- Reset kata sandi juga mencabut seluruh sesi pengguna itu.

## 5. Kata sandi

- Administrator menetapkan kata sandi awal dan dapat mereset kata sandi, dengan aturan yang sama seperti perintah `erp:admin:create`.
- Kata sandi tidak pernah masuk ke audit, log, pesan kilat, atau respons.
- **Batasan:** skema tidak memiliki penanda wajib ganti kata sandi saat login pertama, sehingga hal ini tidak dipaksakan.

## 6. Berlakunya perubahan izin

Izin dimuat per permintaan tanpa cache lintas permintaan. Perubahan peran berlaku pada permintaan berikutnya pengguna tersebut, tanpa perlu mencabut sesi.

## 7. Audit

Setiap operasi dicatat lewat `AuditLogger` dalam transaksi yang sama dengan `strict: true`, sehingga gagal mencatat berarti operasi dibatalkan.

| Event | Isi |
|---|---|
| `user.created`, `user.updated` | Nama dan email (email disanitasi menjadi hash) |
| `user.activated`, `user.deactivated`, `password.reset` | Perubahan status, tanpa kata sandi |
| `user.roles_changed` | Daftar peran ditambah dan dicabut |
| `role.created`, `role.updated`, `role.deleted` | Data peran |
| `role.permissions_changed` | Daftar izin ditambah dan dicabut |

### Penampil log audit

- Hanya baca. Tidak ada rute untuk mengubah, menghapus, atau mengekspor.
- Filter: rentang tanggal, pengguna, awalan event, dan tipe objek. Urutan terbaru lebih dulu, maksimal 100 baris per halaman.
- Nilai JSON ditampilkan sebagai teks yang di-escape.
- Log menampilkan nama pelaku apa adanya, termasuk Super Administrator, agar catatan audit tetap utuh. Karena itu `audit_logs.view` sebaiknya hanya diberikan kepada pihak yang memang berhak.
- **Retensi 7 hari:** log yang berumur lebih dari 7 hari dihapus otomatis setiap pukul 01.00, sehingga halaman ini hanya menampilkan 7 hari terakhir. Rinciannya ada di [Panduan Operasional](OPERATIONS.md).

## 8. Antarmuka

Menu **Administrasi** menampilkan Pengguna, Peran & Hak Akses, dan Log Audit menurut izin masing-masing. Pengaturan Sistem belum tersedia.

- **Pengguna**: daftar dengan pencarian nama atau email serta filter status dan peran. Formulir memakai kotak centang peran; peran di luar plafon tampil nonaktif beserta alasannya.
- **Detail pengguna**: tombol Ubah, Reset Kata Sandi, dan Nonaktifkan atau Aktifkan. Aksi yang tidak boleh dilakukan tetap terlihat dalam keadaan nonaktif, dengan alasan tertulis di halaman.
- **Peran**: daftar dengan jumlah izin dan pengguna. Peran bawaan tampil hanya baca dengan tombol Duplikat. Tombol Hapus nonaktif selama peran dipakai pengguna.
- **Matriks izin**: satu kartu per modul, baris per sumber, kolom per aksi, dengan tombol Pilih Semua dan Kosongkan per modul. Mencentang aksi selain Lihat ikut mencentang Lihat; membatalkan Lihat membatalkan aksi lain pada sumber itu. Kotak di luar plafon nonaktif.
- **Log Audit**: tabel dengan filter, dan dialog detail yang menampilkan nilai lama dan baru sebagai teks.

Semua aksi tulis memakai `useSubmitLock`. Kata sandi tidak pernah ditampilkan kembali setelah dikirim.

## 9. Batasan yang disengaja

- Tanpa 2FA.
- Tanpa alur lupa kata sandi lewat email.
- Tanpa wajib ganti kata sandi saat login pertama.
- Tanpa ekspor, ubah, atau hapus log audit.
- Peran bawaan terkunci di antarmuka.
- Tanpa penghapusan pengguna.
- Tanpa halaman Pengaturan Sistem.

## 10. Contoh: membuat "Staf Master Data (Lihat Saja)"

1. Masuk sebagai Super Administrator, buka **Peran & Hak Akses**, lalu **Tambah Peran**.
2. Isi nama `Staf Master Data (Lihat Saja)`.
3. Pada matriks modul **Data Master**, centang kolom **Lihat** untuk setiap sumber, lalu simpan.
4. Buka **Pengguna**, tambah atau ubah staf tersebut, dan centang peran baru.
5. Pada permintaan berikutnya, staf hanya melihat menu Data Master dan tidak melihat tombol tambah, ubah, atau hapus.

## 11. Pengujian

- `tests/Feature/AccessGuardTest.php`: aturan eskalasi, plafon, dan perlindungan akun.
- `tests/Feature/RoleServiceTest.php`, `tests/Feature/UserServiceTest.php`: layanan, audit, dan berlakunya perubahan izin.
- `tests/Feature/AdminRouteGuardTest.php`, `tests/Feature/AdminUserHttpTest.php`, `tests/Feature/AdminRoleHttpTest.php`, `tests/Feature/AuditLogHttpTest.php`: lapisan HTTP.
- `resources/js/test/admin-user-pages.test.js`, `admin-role-pages.test.js`, `admin-audit-log-page.test.js`: halaman dengan komponen asli dan axe-core.
- `scripts/verify-hardening.sh` Butir 29: rute, autentikasi, izin, pembatas laju, dan jawaban 403.
