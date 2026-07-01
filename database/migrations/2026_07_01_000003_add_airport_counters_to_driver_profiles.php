<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            // State in_area terakhir untuk mendeteksi perpindahan (crossing).
            $table->boolean('last_in_area')->nullable();
            // Berapa kali supir MASUK / KELUAR area bandara (akumulatif).
            $table->unsignedInteger('airport_entries')->default(0);
            $table->unsignedInteger('airport_exits')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['last_in_area', 'airport_entries', 'airport_exits']);
        });
    }
};
