<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Salinan tujuan pencairan pada saat pengajuan dibuat.
     *
     * Supir boleh mengganti tujuan kapan saja. Kalau PDF/email/bukti membaca
     * driver_profiles saat dokumen dibuka, isi dokumen pencairan lama ikut
     * berubah dan jejak auditnya hilang. Karena itu tujuan di-snapshot.
     * Baris lama dibiarkan null dan ditampilkan dengan fallback ke profil.
     */
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('payout_method', 10)->nullable()->after('amount');
            $table->string('payout_provider', 30)->nullable()->after('payout_method');
            $table->string('payout_account', 50)->nullable()->after('payout_provider');
            $table->string('payout_holder_name', 100)->nullable()->after('payout_account');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropColumn([
                'payout_method',
                'payout_provider',
                'payout_account',
                'payout_holder_name',
            ]);
        });
    }
};
