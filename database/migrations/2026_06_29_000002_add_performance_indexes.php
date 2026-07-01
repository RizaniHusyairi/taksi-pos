<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index performa untuk kolom panas. Di SQLite, foreignId()->constrained() TIDAK
 * membuat index pada kolomnya, sehingga query yang memfilter FK / status / role
 * sebelumnya full-scan (terbukti via EXPLAIN QUERY PLAN). Index ini mengubahnya
 * jadi SEARCH USING INDEX, penting saat data tumbuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['driver_id', 'status']); // getBalance, riwayat, dashboard
            $table->index('cso_id');                // dashboard & riwayat CSO
            $table->index('status');                // filter status global
            $table->index('zone_id');               // cek zona dipakai (hapus zona)
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index('booking_id');    // whereHas('booking') — paling sering
            $table->index('payout_status'); // getBalance, withdrawal
            $table->index('withdrawal_id'); // approve/reject/details
            $table->index('created_at');    // laporan & dashboard rentang tanggal
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->index('driver_id');
            $table->index('status');
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->index('status');
            $table->index('out_of_area_since');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'active']); // adminGetUsersByRole, laporan, count
        });

        Schema::table('driver_activities', function (Blueprint $table) {
            $table->index(['user_id', 'created_at']); // log aktivitas driver
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['driver_id', 'status']);
            $table->dropIndex(['cso_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['zone_id']);
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['booking_id']);
            $table->dropIndex(['payout_status']);
            $table->dropIndex(['withdrawal_id']);
            $table->dropIndex(['created_at']);
        });
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropIndex(['driver_id']);
            $table->dropIndex(['status']);
        });
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['out_of_area_since']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'active']);
        });
        Schema::table('driver_activities', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
        });
    }
};
