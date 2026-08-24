<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak setoran CSO di tiap transaksi.
 *
 * PENTING: kolom ini SENGAJA terpisah dari `payout_status`/`withdrawal_id`.
 * Satu transaksi 'CashCSO' punya dua kewajiban berbeda di atas uang yang sama:
 *   - payout_status  : uang ini akan dicairkan ke SUPIR
 *   - deposit_status : uang fisiknya sudah diserahkan CSO ke ADMIN
 * Kalau kolomnya dipakai bersama, menyetujui setoran CSO akan ikut menandai
 * transaksi sebagai sudah dibayar ke supir dan saldo supir langsung rusak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Tanpa constraint FK, mengikuti pola kolom withdrawal_id yang sudah
            // ada di tabel ini (SQLite harus membangun ulang tabel untuk FK baru).
            $table->unsignedBigInteger('cso_deposit_id')->nullable()->after('withdrawal_id');

            // string, bukan enum: menambah kolom enum ke tabel yang SUDAH ADA
            // tidak portabel di SQLite (dev) walau aman di MySQL (produksi).
            // Nilai: Unsettled | Processing | Settled
            $table->string('deposit_status', 20)->default('Unsettled');

            $table->index('cso_deposit_id');
            $table->index('deposit_status');
        });

        // Backfill: seluruh transaksi yang sudah ada dianggap SUDAH beres.
        // Tanpa ini, hari pertama fitur menyala CSO melihat tumpukan setoran
        // berbulan-bulan yang sebenarnya sudah lama diserahkan di luar sistem.
        DB::table('transactions')->update(['deposit_status' => 'Settled']);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['cso_deposit_id']);
            $table->dropIndex(['deposit_status']);
            $table->dropColumn(['cso_deposit_id', 'deposit_status']);
        });
    }
};
