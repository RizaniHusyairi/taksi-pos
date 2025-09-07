<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
             // Kolom id, standar.
            $table->id();

            // Kolom 'key' untuk nama pengaturan, harus unik.
            // Contoh: 'commission_rate', 'app_name', 'maintenance_mode'
            $table->string('key')->unique();

            // Kolom 'value' untuk menyimpan nilai dari pengaturan.
            // Menggunakan TEXT agar fleksibel, bisa menyimpan string panjang atau JSON.
            $table->text('value')->nullable();

            // Kolom created_at dan updated_at.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
