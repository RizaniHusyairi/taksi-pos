<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peringatan pra-giliran ("Giliran Anda sebentar lagi") dikirim saat supir
 * naik ke posisi 2, lalu posisi 1. Kolom ini mengingat posisi TERBAIK yang
 * sudah diberi tahu untuk entri antrian ini, supaya perubahan antrian yang
 * berulang tidak mengirim push yang sama berkali-kali. Entri antrian baru
 * (supir masuk lagi) otomatis mulai dari NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_queues', function (Blueprint $table) {
            $table->unsignedTinyInteger('heads_up_level')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('driver_queues', function (Blueprint $table) {
            $table->dropColumn('heads_up_level');
        });
    }
};
