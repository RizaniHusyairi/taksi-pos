<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config; // Tambahkan ini
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
}
