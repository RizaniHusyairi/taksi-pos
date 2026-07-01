<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lokasi terakhir driver disimpan di profil, supaya peta CSO tetap bisa
     * menampilkan & melacak driver yang sedang OnTrip (sudah keluar dari
     * tabel antrian). Sebelumnya lokasi hanya ada di driver_queues.
     */
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('auto_join_blocked');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->timestamp('location_updated_at')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'location_updated_at']);
        });
    }
};
