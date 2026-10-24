<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konsesi bulanan supir — iuran tetap per bulan ke koperasi.
 *
 * Dua tabel, bukan satu kolom di driver_profiles: konsesi boleh DICICIL, dan
 * tiap cicilan perlu jejaknya sendiri (dari mana uangnya, siapa yang
 * mencatat, ikut pencairan yang mana). Tagihan per bulan disimpan sebagai
 * baris sendiri supaya tunggakan bulan lalu tidak tertimpa bulan ini.
 *
 * Cicilan dari dompet (`source = wallet`) TIDAK memindahkan uang apa pun saat
 * itu juga: uangnya memang sudah tertahan di koperasi (QRIS / tunai ke kasir).
 * Ia cukup mengurangi hak pencairan supir — lihat DriverApiController::
 * hitungSaldo(). Saat supir mencairkan, cicilan itu dikunci ke `withdrawal_id`
 * pencairan tersebut, persis seperti transaksi pemasukannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_concessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->char('period', 7); // YYYY-MM

            // Disalin dari setting SAAT tagihan terbit — mengubah tarif di
            // tengah bulan tidak mengubah tagihan yang sudah berjalan.
            $table->unsignedInteger('amount');

            $table->enum('status', ['Open', 'Paid', 'Waived'])->default('Open');
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('waive_note', 500)->nullable();

            $table->timestamps();

            $table->unique(['driver_id', 'period']);
            $table->index(['period', 'status']);
        });

        Schema::create('concession_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concession_id')->constrained('driver_concessions')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('amount');

            // wallet     = dipotong dari saldo dompet oleh supir sendiri
            // cash_admin = supir bayar tunai, dicatat admin (jalan keluar bagi
            //              supir yang saldonya nol/negatif)
            // (self_trip ditambahkan di migrasi 2026_10_25_000001)
            $table->enum('source', ['wallet', 'cash_admin']);

            $table->foreignId('withdrawal_id')->nullable()->constrained('withdrawals')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->index(['driver_id', 'source', 'withdrawal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concession_payments');
        Schema::dropIfExists('driver_concessions');
    }
};
