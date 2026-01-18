<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('withdrawal_id')->nullable()->after('booking_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Hapus kolom jika migrasi di-rollback
            $table->dropColumn('withdrawal_id');
            
            // Jika tadi menambahkan foreign key, hapus constraint-nya dulu:
            // $table->dropForeign(['withdrawal_id']);
            // $table->dropColumn('withdrawal_id');
        });
    }
};