# ERP Distribusi / Trading (Laravel + Vue Monolith)

## Gambaran Proyek
Repositori ini merupakan implementasi sistem ERP untuk operasional distribusi dan trading berbasis monolitik (Laravel + Vue).

## Status Fase: Fase 0 (Fondasi Lingkungan & Hardening Dasar)
Saat ini proyek berada pada **Fase 0**. Fokus fase ini adalah:
- Lingkungan kerja reproducible berbasis WSL Ubuntu + Docker Engine / Docker Desktop.
- Repositori Git bersih dari rahasia dengan hook otomatis (Gitleaks, verifikasi rahasia, Conventional Commits).
- Infrastruktur Docker hardened (PHP-FPM, Nginx Unprivileged, MariaDB 12.3 LTS, Node dev tooling) dengan pembuktian skrip verifikasi otomatis.

> **Catatan Arsitektur:** Pada Fase 0 tidak ada instalasi kode framework aplikasi (Laravel/Vue), pembuatan tabel database, maupun fitur bisnis. Implementasi aplikasi dimulai pada Fase 1 setelah Fase 0 terbukti lulus verifikasi.
