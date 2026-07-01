<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Token acak untuk URL struk publik, supaya struk TIDAK bisa diakses dengan
 * menebak ID transaksi yang berurutan (kebocoran privasi: nama supir, tujuan,
 * nominal). URL struk berubah dari /receipt/{id} menjadi /receipt/{token}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('receipt_token', 64)->nullable()->unique()->after('id');
        });

        // Backfill token untuk transaksi lama.
        foreach (DB::table('transactions')->whereNull('receipt_token')->pluck('id') as $id) {
            DB::table('transactions')->where('id', $id)->update(['receipt_token' => Str::random(40)]);
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['receipt_token']);
            $table->dropColumn('receipt_token');
        });
    }
};
