<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak dugaan pemalsuan lokasi (fake GPS) per supir.
 *
 * Disimpan permanen di profil — BUKAN hanya di log aktivitas — karena yang
 * dibutuhkan admin adalah pola, bukan kejadian tunggal. Satu lompatan aneh bisa
 * saja GPS yang bermasalah; dua puluh lompatan dalam seminggu adalah keputusan
 * yang harus diambil pengurus koperasi. Lihat DriverApiController::periksaLokasiPalsu().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            // Berapa kali fix mencurigakan ditolak. Tidak pernah direset
            // otomatis — hanya admin yang boleh memaafkan.
            $table->unsignedInteger('spoof_strikes')->default(0)->after('track_lng');

            // Kapan terakhir, dan kenapa ('mock_provider' | 'teleport').
            // Alasannya disimpan supaya admin bisa membedakan bukti tegas
            // (Android sendiri bilang lokasinya palsu) dari bukti tak langsung.
            $table->timestamp('last_spoof_at')->nullable()->after('spoof_strikes');
            $table->string('last_spoof_reason', 32)->nullable()->after('last_spoof_at');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['spoof_strikes', 'last_spoof_at', 'last_spoof_reason']);
        });
    }
};
