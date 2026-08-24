<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setoran tunai CSO ke admin.
 *
 * CSO menerima uang fisik setiap order bermetode 'CashCSO'. Sebelum tabel ini,
 * penyerahan uang itu ke admin tidak punya jejak apa pun di sistem — selisih
 * baru ketahuan saat tutup buku. Satu baris di sini = satu kali serah terima,
 * mencakup satu atau beberapa TANGGAL sekaligus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cso_deposits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cso_id')->constrained('users')->onDelete('cascade');

            // Nominal SELALU hasil hitungan server dari transaksi yang tercakup,
            // tidak pernah kiriman klien. Lihat CsoApiController::storeDeposit().
            $table->decimal('amount', 12, 2);

            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');

            // Tanggal-tanggal yang disetor, mis. ["2026-08-23","2026-08-24"].
            // Sumber kebenaran tetap kolom cso_deposit_id di tabel transactions;
            // ini untuk tampilan & audit supaya tidak perlu query balik.
            $table->json('period_dates')->nullable();
            $table->unsignedInteger('transactions_count')->default(0);

            $table->text('note')->nullable();        // catatan CSO
            $table->text('admin_note')->nullable();  // alasan tolak / catatan admin
            $table->string('proof_image')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['cso_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cso_deposits');
    }
};
