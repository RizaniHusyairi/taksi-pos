<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tujuan pencairan supir: sebelumnya selalu Bank BTN, kini boleh e-wallet.
     *
     * Kolom bank lama (bank_name, account_number) sengaja tidak diutak-atik —
     * supir yang sudah mengisi rekening tetap valid karena payout_method
     * default-nya 'bank'. Provider disimpan sebagai string, bukan enum, supaya
     * menambah dompet baru nanti tidak butuh doctrine/dbal.
     */
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('payout_method', 10)->default('bank')->after('account_number');
            $table->string('ewallet_provider', 20)->nullable()->after('payout_method');
            $table->string('ewallet_number', 20)->nullable()->after('ewallet_provider');
            $table->string('ewallet_holder_name', 100)->nullable()->after('ewallet_number');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'payout_method',
                'ewallet_provider',
                'ewallet_number',
                'ewallet_holder_name',
            ]);
        });
    }
};
