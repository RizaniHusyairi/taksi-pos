<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            // Satu penilaian per trip (booking). unique() mencegah rating ganda.
            $table->foreignId('booking_id')->unique()->constrained()->onDelete('cascade');
            $table->unsignedTinyInteger('stars'); // 1..5
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
