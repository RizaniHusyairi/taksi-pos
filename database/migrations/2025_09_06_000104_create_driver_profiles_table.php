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
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            // Kunci asing yang terhubung ke tabel users
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['offline', 'standby', 'ontrip'])->default('offline');
            
            // Menambah kolom nomor urut tetap (L1, L2, dst)
            // Kita simpan angkanya saja (integer) agar mudah dihitung rotasinya
            $table->integer('line_number')->nullable()->unique();
            $table->date('last_queue_date')->nullable();


            // Kolom-kolom spesifik driver
            $table->string('car_model'); // <-- Ubah dari 'car'
            $table->string('plate_number'); // <-- Ubah dari 'plate'
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable(); 

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_profiles');
    }
};
