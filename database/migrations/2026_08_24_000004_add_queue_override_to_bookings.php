<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tandai order yang MELEWATI giliran antrian sebagai data, bukan kalimat.
 *
 * Sebelumnya fakta ini hanya hidup sebagai teks bebas di
 * `driver_activities.description` ("Mendahului antrian (giliran: Budi)").
 * Cukup untuk dibaca manusia satu per satu, tapi mustahil dijadikan laporan
 * yang bisa dipercaya: menghitung rasio override per CSO berarti mem-parsing
 * kalimat berbahasa Indonesia, dan angkanya akan diam-diam salah begitu
 * kalimatnya diubah sedikit saja.
 *
 * Log aktivitas TETAP ditulis — itu jejak yang dibaca per supir. Kolom ini
 * untuk pertanyaan yang berbeda: "CSO mana yang paling sering melewati
 * giliran?" Lihat ApiController::adminGetFraudSignals().
 *
 * Catatan: order LAMA tidak punya nilai ini (default false), jadi laporannya
 * baru bermakna untuk data sejak migrasi ini dijalankan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('queue_override')->default(false)->after('status');

            // Supir yang gilirannya dilewati. Tanpa ini kita hanya tahu ADA
            // yang dilewati, bukan siapa — padahal pola "selalu melewati orang
            // yang sama" adalah sinyal yang jauh lebih kuat daripada sekadar
            // jumlah override.
            $table->foreignId('skipped_driver_id')->nullable()->after('queue_override')
                ->constrained('users')->nullOnDelete();

            // Laporan selalu memfilter override dalam rentang tanggal.
            $table->index(['queue_override', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['queue_override', 'created_at']);
            $table->dropConstrainedForeignId('skipped_driver_id');
            $table->dropColumn('queue_override');
        });
    }
};
