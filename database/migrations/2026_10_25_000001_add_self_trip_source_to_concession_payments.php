<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fee flat trip mandiri ("Dapat Penumpang Sendiri") dialihkan menjadi cicilan
 * konsesi — lihat ConcessionService::payFromSelfTrip().
 *
 * source = self_trip: uangnya tetap utang CashDriver di transaksi terkait;
 * yang berubah hanya peruntukannya, jadi cicilan ini TIDAK mengurangi saldo
 * dompet lagi (hanya source = wallet yang mengurangi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concession_payments', function (Blueprint $table) {
            $table->enum('source', ['wallet', 'cash_admin', 'self_trip'])->change();

            // Transaksi trip mandiri asalnya. Unik supaya satu trip tidak bisa
            // dihitung dua kali sebagai cicilan.
            $table->foreignId('transaction_id')->nullable()->unique()->after('withdrawal_id')
                ->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('concession_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_id');
        });

        \Illuminate\Support\Facades\DB::table('concession_payments')->where('source', 'self_trip')->delete();

        Schema::table('concession_payments', function (Blueprint $table) {
            $table->enum('source', ['wallet', 'cash_admin'])->change();
        });
    }
};
