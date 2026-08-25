<?php

use App\Models\CsoDeposit;
use App\Models\DriverDeposit;
use App\Models\Transaction;
use App\Models\Withdrawals;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat peristiwa yang perlu diketahui admin — isi ikon lonceng di header.
 *
 * Sebelumnya lonceng itu tombol mati dengan titik merah yang di-hardcode di
 * markup: selalu menyala, tanpa peduli ada tidaknya sesuatu. Indikator yang
 * selalu menyala lebih buruk daripada tidak ada indikator, karena dalam
 * seminggu admin berhenti mempercayainya.
 *
 * Status "sudah dibaca" TIDAK disimpan di sini melainkan sebagai satu stempel
 * waktu per pengguna (users.notifications_read_at) — lihat migrasi berikutnya
 * untuk alasannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();

            // string, bukan enum: menambah jenis baru nanti tidak perlu
            // mengubah skema, dan enum tidak portabel di SQLite (dev).
            // Nilai: withdrawal | cso_deposit | driver_deposit | method_dispute
            $table->string('type', 32);

            $table->string('title');
            $table->string('body', 500)->nullable();

            // Tujuan navigasi hash panel admin, mis. '#withdrawals'.
            $table->string('link', 64)->nullable();

            // info | warning | danger — hanya mewarnai baris.
            $table->string('level', 16)->default('info');

            // Penelusuran balik ke record asal. Tanpa FK: recordnya boleh
            // terhapus tanpa membuat riwayat ikut hilang.
            $table->string('related_type', 64)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();

            $table->timestamps();

            // created_at dipakai untuk urutan DAN untuk menghitung yang belum
            // dibaca (dibandingkan dengan stempel per pengguna).
            $table->index('created_at');
            $table->index('type');
        });

        $this->backfill();
    }

    /**
     * Isi awal dari antrean yang MASIH menunggu keputusan.
     *
     * Tanpa ini lonceng menyala kosong pada hari pertama padahal antreannya
     * mungkin sudah menumpuk — kesan pertama yang justru mengajarkan admin
     * untuk mengabaikannya.
     */
    private function backfill(): void
    {
        $baris = [];
        $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');

        foreach (Withdrawals::with('driver')->where('status', 'Pending')->get() as $w) {
            $baris[] = [
                'type' => 'withdrawal', 'level' => 'warning', 'link' => '#withdrawals',
                'title' => 'Pencairan dana menunggu persetujuan',
                'body' => ($w->driver->name ?? 'Supir') . ' mengajukan ' . $rp($w->amount) . '.',
                'related_type' => 'withdrawal', 'related_id' => $w->id,
                'created_at' => $w->created_at, 'updated_at' => $w->created_at,
            ];
        }

        foreach (CsoDeposit::with('cso')->where('status', 'Pending')->get() as $d) {
            $baris[] = [
                'type' => 'cso_deposit', 'level' => 'info', 'link' => '#cso-deposits',
                'title' => 'Setoran tunai CSO menunggu verifikasi',
                'body' => ($d->cso->name ?? 'CSO') . ' menyetor ' . $rp($d->amount)
                    . ' (' . $d->transactions_count . ' transaksi).',
                'related_type' => 'cso_deposit', 'related_id' => $d->id,
                'created_at' => $d->created_at, 'updated_at' => $d->created_at,
            ];
        }

        foreach (DriverDeposit::with('driver')->where('status', 'Pending')->get() as $d) {
            $baris[] = [
                'type' => 'driver_deposit', 'level' => 'info', 'link' => '#driver-deposits',
                'title' => 'Setoran tunai supir menunggu verifikasi',
                'body' => ($d->driver->name ?? 'Supir') . ' menyetor ' . $rp($d->amount)
                    . ' (' . $d->transactions_count . ' transaksi).',
                'related_type' => 'driver_deposit', 'related_id' => $d->id,
                'created_at' => $d->created_at, 'updated_at' => $d->created_at,
            ];
        }

        foreach (Transaction::with('booking.driver')->where('method_dispute_status', 'Open')->get() as $t) {
            $waktu = $t->method_disputed_at ?? $t->updated_at;
            $baris[] = [
                'type' => 'method_dispute', 'level' => 'danger', 'link' => '#method-disputes',
                'title' => 'Sengketa metode pembayaran dibuka',
                'body' => ($t->booking->driver->name ?? 'Supir') . ' menyanggah metode bayar order #'
                    . $t->booking_id . ' (' . $rp($t->amount) . ').',
                'related_type' => 'transaction', 'related_id' => $t->id,
                'created_at' => $waktu, 'updated_at' => $waktu,
            ];
        }

        foreach (array_chunk($baris, 200) as $potongan) {
            DB::table('admin_notifications')->insert($potongan);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
