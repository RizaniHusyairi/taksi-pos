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
        Schema::create('bookings', function (Blueprint $table) {
            // Kolom id (BIGINT, UNSIGNED, AUTO_INCREMENT, PRIMARY)
            $table->id();

            // Foreign Key ke tabel 'users' untuk CSO yang membuat booking
            $table->foreignId('cso_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            // Foreign Key ke tabel 'users' untuk Supir yang ditugaskan
            $table->foreignId('driver_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            // Foreign Key ke tabel 'zones' untuk Zona Tujuan
            $table->foreignId('zone_id')
                  ->constrained('zones')
                  ->onDelete('cascade');

            // Harga/tarif perjalanan saat booking dibuat.
            // Disimpan di sini untuk arsip, seandainya tarif di tabel 'zones' berubah.
            $table->decimal('price', 10, 2);

            // Status booking saat ini.
            $table->string('status')->default('Assigned');
            // Contoh status: Assigned, Completed, Paid, CashDriver, Cancelled

            // Kolom created_at dan updated_at (TIMESTAMP)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
