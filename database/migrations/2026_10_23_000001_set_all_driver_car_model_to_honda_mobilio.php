<?php

use App\Models\DriverProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seluruh armada Angkasa Jaya adalah Honda Mobilio — lihat
 * DriverProfile::CAR_MODEL. Data lama diseragamkan, dan kolomnya diberi
 * default supaya baris yang dibuat di luar Eloquent pun ikut benar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('driver_profiles')->update(['car_model' => DriverProfile::CAR_MODEL]);

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('car_model')->default(DriverProfile::CAR_MODEL)->change();
        });
    }

    public function down(): void
    {
        // Model mobil lama tidak disimpan, jadi tidak bisa dipulihkan; cukup
        // lepas default kolomnya.
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('car_model')->default(null)->change();
        });
    }
};
