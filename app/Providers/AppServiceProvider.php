<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config; // Tambahkan ini
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema; // Tambahkan ini
use App\Models\Setting; // Tambahkan ini

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        // Pastikan tabel settings ada (agar tidak error saat migrate fresh)
        if (Schema::hasTable('settings')) {
            
            // Ambil semua setting dari DB
            $settings = Setting::all()->pluck('value', 'key');

            // Cek apakah ada setting mail_host, jika ada, override config
            if (isset($settings['mail_host'])) {
                
                // Override konfigurasi Mailer 'smtp'
                Config::set('mail.mailers.smtp.host', $settings['mail_host']);
                Config::set('mail.mailers.smtp.port', $settings['mail_port']);
                Config::set('mail.mailers.smtp.encryption', $settings['mail_encryption']);
                Config::set('mail.mailers.smtp.username', $settings['mail_username']);
                Config::set('mail.mailers.smtp.password', $settings['mail_password']);
                
                // Override konfigurasi From Address
                Config::set('mail.from.address', $settings['mail_from_address'] ?? $settings['mail_username']);
                Config::set('mail.from.name', $settings['mail_from_name'] ?? env('APP_NAME'));
            }
        }
    }

    /**
     * Rem laju untuk seluruh grup route `api` (dipasang di bootstrap/app.php).
     *
     * Laravel 11 TIDAK memasang throttle apa pun pada grup api — tanpa ini
     * seluruh API terbuka tanpa batas.
     *
     * Kenapa limiter bernama, bukan sekadar `throttle:120,1`: guard bawaan
     * aplikasi ini adalah `web` (config/auth.php), sehingga `$request->user()`
     * bernilai null untuk permintaan bertoken dan ThrottleRequests akan jatuh
     * ke kunci per-IP. Puluhan supir di balik satu jaringan WiFi bandara akan
     * berbagi satu ember kuota dan saling mengunci — itu bukan keamanan,
     * itu gangguan layanan. Di sini kunci diambil dari token Sanctum lebih
     * dulu, jadi kuota benar-benar per-pengguna.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // Guard sanctum dipanggil eksplisit: guard default 'web' tidak
            // mengenali bearer token.
            $userId = optional($request->user('sanctum'))->id;

            return $userId
                // 120/menit per pengguna. Aplikasi supir mengirim heartbeat
                // tiap 75 detik (~1/menit) plus polling profil — sangat longgar
                // untuk pemakaian wajar, tapi menahan skrip yang membanjiri.
                ? Limit::perMinute(120)->by('api-user:' . $userId)
                // Belum login / Management API (kunci API, bukan sesi user):
                // per-IP dengan kuota lebih besar karena satu IP kantor bisa
                // mewakili banyak orang.
                : Limit::perMinute(300)->by('api-ip:' . $request->ip());
        });
    }
}
