<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setoran tunai SUPIR ke admin — pelunasan utang komisi.
 *
 * Sebelum ini, utang supir hanya bisa lunas lewat PENCAIRAN: sistem memotong
 * utang dari hak pemasukannya. Akibatnya supir yang saldo bersihnya negatif
 * cukup tidak pernah menekan "Cairkan", dan uang koperasi yang ia pegang tidak
 * punya jatuh tempo sama sekali. Ini memberi jalan kedua: bayar tunai.
 *
 * SENGAJA memakai `payout_status` yang sudah ada, bukan buku besar terpisah
 * seperti setoran CSO (`deposit_status`). Alasannya berbeda secara mendasar:
 * tunai yang dipegang CSO dan hak pencairan supir adalah dua kewajiban yang
 * berjalan bersamaan, sedangkan utang supir dan pencairan supir adalah SATU
 * kewajiban yang sama dilihat dari dua arah — satu transaksi tidak boleh bisa
 * dilunasi lewat setoran sekaligus dipotong lewat pencairan. Berbagi kolom
 * status membuat hal itu mustahil secara struktural, bukan sekadar dijaga
 * kode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();

            // Nominal yang disetor = FEE/komisi yang terutang, BUKAN tarif
            // kotor. Supir menyetor bagian koperasi, bukan seluruh ongkos
            // penumpang. Dihitung server, tidak pernah diterima dari klien.
            $table->decimal('amount', 12, 2);

            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');

            // Tanggal-tanggal yang tercakup, diambil dari transaksi yang
            // BENAR-BENAR terkunci (bukan dari permintaan klien).
            $table->json('period_dates')->nullable();
            $table->unsignedInteger('transactions_count')->default(0);

            $table->text('note')->nullable();        // catatan supir
            $table->text('admin_note')->nullable();  // alasan admin (wajib saat menolak)
            $table->string('proof_image')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['driver_id', 'status']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Setoran supir yang mencakup transaksi ini (null = belum pernah).
            // Sejajar dengan `withdrawal_id`: keduanya menandai APA yang
            // mengubah `payout_status`, dan hanya satu yang boleh terisi.
            $table->foreignId('driver_deposit_id')->nullable()->after('withdrawal_id')
                ->constrained('driver_deposits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_deposit_id');
        });

        Schema::dropIfExists('driver_deposits');
    }
};
