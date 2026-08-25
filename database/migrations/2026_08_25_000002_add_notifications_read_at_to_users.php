<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kapan seorang admin terakhir membuka ikon lonceng.
 *
 * Jumlah "belum dibaca" = notifikasi yang lahir setelah stempel ini. Membuka
 * lonceng memperbarui stempelnya ke sekarang.
 *
 * PENTING — kenapa di sini, bukan kolom `read_at` di baris notifikasi:
 * panel bisa punya lebih dari satu admin. Kalau status baca menempel pada
 * notifikasinya (dan karenanya dibagi bersama), admin kedua tidak akan pernah
 * melihat tanda untuk hal yang kebetulan sudah dibuka admin pertama. Bug yang
 * sunyi, dan baru ketahuan saat ada yang terlewat. Satu stempel per pengguna
 * menghindarinya tanpa perlu tabel pivot.
 *
 * Konsekuensi yang diterima: tidak ada "tandai satu item dibaca" — hanya
 * "semua terbaca saat lonceng dibuka". Itu memang yang diharapkan orang dari
 * sebuah ikon lonceng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('notifications_read_at')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notifications_read_at');
        });
    }
};
