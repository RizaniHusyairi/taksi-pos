# taksi-pos

Laravel 12 / PHP 8.2+ (Laragon PHP 8.5 — lihat memory `taksi-pos-php85-env`) + Flutter app driver.

## Peta cepat — jangan eksplorasi ulang tiap sesi

- `routes/api.php` (211 baris) — hampir semua endpoint. `routes/web.php` hanya login + halaman.
- `app/Http/Controllers/Api/` — `ApiController` (umum), `DriverApiController`, `CsoApiController`, `ManagementApiController`, `ApiAuthController`.
- `app/Models/` — Booking, Transaction, DriverProfile, DriverQueue, DriverActivity, DriverLocationLog, DriverDeposit, CsoDeposit, Withdrawals, Zone, Rating, Setting, User, ApiClient.
- `app/Services/` — `WhatsAppService`, `ImageWatermark`.
- UI web tinggal **admin saja**: `resources/views/pages/admin.blade.php` (file besar — selalu `grep -n` dulu, jangan baca utuh) + `login.blade.php`.
- **Halaman web CSO & driver sudah dihapus** — keduanya hanya lewat aplikasi Flutter `driver_app/`. Role `cso` dan `driver` ditolak saat login web (`AuthController::login`), dan `RoleMiddleware` menjawab 403 untuk request JSON (bukan redirect).
- Flutter: `driver_app/lib/{screens,providers,services,models,widgets}`.

## Perintah

```bash
php artisan test --filter=NamaTest
```

Jalankan app: preview `taksi-pos` dari `.claude/launch.json` (`php artisan serve` port 8000). Jangan start server lewat Bash.

## Aturan kerja

- **Selalu terapkan skill `hemat-token`** untuk setiap tugas di project ini: grep sebelum read, baca sebagian file saja, batasi output shell, jangan spawn subagent kecuali diminta.
- Driver memang TIDAK bisa menolak order — bukan bug (memory `driver-no-reject-order-by-design`).
- Sebelum menindak dugaan kecurangan, baca memory `fraud-audit-open-items` untuk batas heuristiknya.
