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

            // Kolom-kolom spesifik driver
            $table->string('car');
            $table->string('plate');
            $table->enum('status', ['available', 'ontrip', 'offline'])->default('offline');

            $table->timestamps();
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
