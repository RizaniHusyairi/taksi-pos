<?php

namespace App\Http\Middleware;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful as SanctumStateful;

/**
 * Sanctum mengenali panel admin (request "dari frontend sendiri") HANYA dari
 * header Referer/Origin. Browser dengan pengaturan privasi ketat atau
 * ekstensi pemblokir tidak mengirim Referer sama sekali, sehingga sesi login
 * yang sah tetap ditolak 401 "Unauthenticated" di setiap panggilan API.
 *
 * Tambahan di sini: `Sec-Fetch-Site: same-origin`. Header ini dipasang oleh
 * browser sendiri (tidak bisa dipalsukan JavaScript situs lain) dan tidak
 * ikut dihapus oleh pengaturan privasi Referer. POST/PUT/DELETE tetap wajib
 * lolos verifikasi CSRF seperti biasa.
 */
class EnsureFrontendRequestsAreStateful extends SanctumStateful
{
    public static function fromFrontend($request)
    {
        return parent::fromFrontend($request)
            || $request->headers->get('sec-fetch-site') === 'same-origin';
    }
}
