<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Karcis terbit saat supir mengonfirmasi berangkat menjemput ("SAYA JEMPUT"),
 * bukan saat CSO membuat order.
 *
 * Sebelumnya karcis bisa dicetak begitu order dibuat — termasuk untuk supir
 * yang akhirnya tidak datang dan diganti lewat "Ganti Supir". Kertas yang
 * sudah di tangan penumpang lalu menunjuk mobil yang salah.
 *
 * - `pickup_confirmed_at` : kapan supir mengonfirmasi. NULL = karcis terkunci.
 * - `pickup_lat/lng`      : posisi supir saat konfirmasi. Sinyal audit untuk
 *                           pola "tekan dari warung, tidak berangkat".
 * - `pickup_source`       : `button` | `notification` | `auto_location`.
 * - `assigned_lat/lng`    : posisi supir saat order DITERIMA, pembanding untuk
 *                           konfirmasi otomatis (supir lupa menekan tapi
 *                           mobilnya sudah bergerak).
 * - `ticket_wa_sent_at`   : WA karcis ke penumpang kini dikirim manual oleh CSO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('assigned_lat', 10, 7)->nullable()->after('skipped_driver_id');
            $table->decimal('assigned_lng', 10, 7)->nullable()->after('assigned_lat');
            $table->timestamp('pickup_confirmed_at')->nullable()->after('assigned_lng');
            $table->decimal('pickup_lat', 10, 7)->nullable()->after('pickup_confirmed_at');
            $table->decimal('pickup_lng', 10, 7)->nullable()->after('pickup_lat');
            $table->string('pickup_source', 20)->nullable()->after('pickup_lng');
            $table->timestamp('ticket_wa_sent_at')->nullable()->after('pickup_source');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'assigned_lat', 'assigned_lng', 'pickup_confirmed_at',
                'pickup_lat', 'pickup_lng', 'pickup_source', 'ticket_wa_sent_at',
            ]);
        });
    }
};
