<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sengketa metode pembayaran: supir menyanggah bahwa ia menerima uang tunai.
 *
 * Masalah yang diselesaikan: CSO memilih sendiri `method` saat membuat order.
 * Kalau ia menerima tunai dari penumpang lalu mencatatnya sebagai 'CashDriver',
 * uangnya ada di tangan CSO tetapi sistem menagih komisinya kepada SUPIR — dan
 * CSO tidak punya kewajiban setoran sama sekali (`cashCsoBelumSetor` hanya
 * melihat 'CashCSO'). Supir baru sadar saat dompetnya minus, dan tidak punya
 * satu pun cara untuk membantahnya.
 *
 * Ini tidak bisa dicegah oleh kode — server tidak tahu uang fisik berpindah ke
 * siapa. Yang bisa dilakukan adalah memberi supir HAK SUARA dan meninggalkan
 * jejak yang bisa diputus admin.
 *
 * Catatan tentang kolom: memakai string biasa, bukan enum. Menambah kolom enum
 * ke tabel yang SUDAH ADA tidak portabel di SQLite (dipakai saat test) walau
 * aman di MySQL — pelajaran yang sama sudah dicatat di migrasi
 * 2026_08_24_000002.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // null = tidak ada sengketa. Open | Upheld | Rejected
            $table->string('method_dispute_status', 12)->nullable()->after('deposit_status');

            // Alasan supir — inilah yang dibaca admin saat memutuskan.
            $table->text('method_dispute_note')->nullable()->after('method_dispute_status');
            $table->timestamp('method_disputed_at')->nullable()->after('method_dispute_note');

            // Keputusan admin.
            $table->text('method_admin_note')->nullable()->after('method_disputed_at');
            $table->timestamp('method_resolved_at')->nullable()->after('method_admin_note');
            $table->unsignedBigInteger('method_resolved_by')->nullable()->after('method_resolved_at');

            // Metode SEBELUM dikoreksi. Tanpa ini, sengketa yang dikabulkan
            // menghapus jejak kesalahannya sendiri: barisnya jadi terlihat
            // seolah sejak awal dicatat benar, dan pola CSO yang berulang kali
            // salah-label tidak akan pernah terlihat.
            $table->string('original_method', 12)->nullable()->after('method_resolved_by');

            $table->index('method_dispute_status');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['method_dispute_status']);
            $table->dropColumn([
                'method_dispute_status',
                'method_dispute_note',
                'method_disputed_at',
                'method_admin_note',
                'method_resolved_at',
                'method_resolved_by',
                'original_method',
            ]);
        });
    }
};
