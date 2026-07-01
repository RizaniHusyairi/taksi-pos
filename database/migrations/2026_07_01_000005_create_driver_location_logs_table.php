<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jejak (breadcrumb) pergerakan supir → dipakai menggambar RUTE di peta admin.
        Schema::create('driver_location_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamp('recorded_at');
            $table->index(['user_id', 'recorded_at']); // query rute per supir per rentang waktu
        });

        // Titik terakhir yang DILOG (untuk throttle jarak — hemat storage & rapi).
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->decimal('track_lat', 10, 7)->nullable();
            $table->decimal('track_lng', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_location_logs');
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['track_lat', 'track_lng']);
        });
    }
};
