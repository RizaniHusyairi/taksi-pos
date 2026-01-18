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
        Schema::create('driver_queues', function (Blueprint $table) {
            $table->id();
            // Driver siapa yang antri
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade'); 
            $table->integer('sort_order')->default(1000); // Urutan antrian, default 1000 (bawah)
            // Lokasi saat masuk antrian (opsional, buat audit)
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            // created_at otomatis menjadi "Nomor Antrian"
            $table->timestamps(); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_queues');
    }
};
