# Secrets Directory

Direktori ini ditujukan untuk penyimpanan rahasia lokal (misalnya berkas kredensial dan kata sandi root database untuk lingkungan pengembangan).

## Aturan Keamanan
1. Seluruh berkas di dalam direktori `secrets/` **dilarang keras** di-commit ke repositori Git, kecuali berkas `secrets/README.md` ini.
2. Setiap berkas rahasia di direktori ini diabaikan oleh `.gitignore` (`secrets/*` dan `!secrets/README.md`).
3. Berkas rahasia harus memiliki permission ketat (`chmod 600`):
   ```bash
   chmod 600 secrets/db_root_password.txt
   ```
4. Rahasia database root dipasang ke container MariaDB via mekanisme Docker Compose `secrets` (`/run/secrets/db_root_password`), sehingga tidak pernah terekspos di environment container aplikasi maupun variabel lingkungan global.
