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
        Schema::create('withdrawals', function (Blueprint $table) {
           $table->id();

            // Foreign Key ke tabel 'users' untuk supir yang mengajukan.
            $table->foreignId('driver_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            // Jumlah dana yang diajukan untuk ditarik.
            $table->decimal('amount', 10, 2);

            
            // Status pengajuan saat ini.
            $table->enum('status', ['Pending', 'Approved', 'Rejected', 'Paid'])
            ->default('Pending');
            
            $table->string('proof_image')->nullable();  
            // Waktu pengajuan dibuat.
            $table->timestamp('requested_at')->nullable();

            // Waktu pengajuan diproses (disetujui/ditolak) oleh admin.
            $table->timestamp('processed_at')->nullable();

            // Timestamps standar Laravel (created_at, updated_at)
            // Kita bisa menggunakan created_at sebagai requested_at jika mau.
            // Namun, memiliki kolom requested_at memberikan fleksibilitas lebih.
            $table->timestamps(); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
