<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\CsoDeposit;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data contoh untuk MENCOBA FITUR SETORAN TUNAI CSO.
 *
 * Jalankan:  php artisan db:seed --class=CsoDepositDummySeeder
 *
 * Kenapa perlu seeder tersendiri padahal sudah ada DummyTransactionSeeder:
 * migrasi 2026_08_24_000002 mem-backfill SEMUA transaksi lama menjadi
 * 'Settled', jadi data contoh yang sudah ada tidak menyisakan satu rupiah pun
 * untuk disetor. Seeder ini sengaja membentuk ketiga keadaan sekaligus:
 *
 *   1. Unsettled  -> muncul di layar "Setoran" sebagai tanggal yang bisa dipilih
 *   2. Processing -> setoran Pending, menunggu verifikasi admin
 *   3. Settled    -> setoran Approved, tampil di riwayat
 *
 * Ikut disisipkan transaksi QRIS pada tanggal yang sama. QRIS TIDAK boleh
 * muncul di daftar setoran tunai — kalau ia muncul, berarti ada yang salah
 * pada Transaction::cashCsoBelumSetor().
 *
 * AMAN DIULANG. Semua baris buatan seeder ini ditandai (nomor penumpang
 * berawalan DUMMY_PHONE_PREFIX, dan setoran bertanda DEPOSIT_NOTE_MARK);
 * hanya baris bertanda itu yang dihapus saat dijalankan ulang.
 */
class CsoDepositDummySeeder extends Seeder
{
    /** Penanda data contoh. Sengaja beda dari DummyTransactionSeeder ('0899000'). */
    public const DUMMY_PHONE_PREFIX = '0899111';

    /** Penanda setoran contoh, agar pembersihan tidak menyentuh setoran asli. */
    public const DEPOSIT_NOTE_MARK = '[DATA CONTOH SETORAN]';

    /** Berapa hari terakhir yang menyisakan tunai belum disetor. */
    private const HARI_OUTSTANDING = 6;

    /** Transaksi tunai per hari. */
    private const TUNAI_PER_HARI = 3;

    public function run(): void
    {
        $csos = User::where('role', 'cso')->orderBy('id')->get();
        $drivers = User::where('role', 'driver')->orderBy('id')->get();
        $zones = Zone::orderBy('id')->get();
        $admin = User::where('role', 'admin')->orderBy('id')->first();

        if ($csos->isEmpty() || $drivers->isEmpty() || $zones->isEmpty()) {
            $this->command->error('Butuh minimal 1 CSO, 1 supir, dan 1 zona. Jalankan UserSeeder & ZoneSeeder dulu.');
            return;
        }

        $dihapus = $this->bersihkan();
        if ($dihapus['booking'] > 0 || $dihapus['setoran'] > 0) {
            $this->command->info(
                "Membersihkan seeding sebelumnya: {$dihapus['booking']} booking, {$dihapus['setoran']} setoran."
            );
        }

        $iDriver = 0;
        $iZona = 0;
        $nomorUrut = 0;
        $ringkasan = [];

        DB::transaction(function () use (
            $csos, $drivers, $zones, $admin,
            &$iDriver, &$iZona, &$nomorUrut, &$ringkasan
        ) {
            foreach ($csos as $cso) {
                $totalOutstanding = 0.0;
                $jmlOutstanding = 0;

                // --- 1. Tunai yang BELUM disetor: 6 hari terakhir ------------
                for ($h = 0; $h < self::HARI_OUTSTANDING; $h++) {
                    $tanggal = now()->subDays($h);

                    for ($n = 0; $n < self::TUNAI_PER_HARI; $n++) {
                        $waktu = $tanggal->copy()->setTime(7 + $n * 4, random_int(0, 59));
                        $trx = $this->buatOrder(
                            $cso, $drivers[$iDriver++ % $drivers->count()],
                            $zones[$iZona++ % $zones->count()],
                            'CashCSO', $waktu, ++$nomorUrut
                        );
                        $totalOutstanding += (float) $trx->amount;
                        $jmlOutstanding++;
                    }

                    // Pembanding: QRIS di hari yang sama, tidak boleh ikut terhitung.
                    $this->buatOrder(
                        $cso, $drivers[$iDriver++ % $drivers->count()],
                        $zones[$iZona++ % $zones->count()],
                        'QRIS', $tanggal->copy()->setTime(12, 30), ++$nomorUrut
                    );
                }

                // --- 2. Setoran PENDING (transaksi 'Processing') -------------
                $pendingTrx = collect();
                $tglPending = now()->subDays(8);
                for ($n = 0; $n < 2; $n++) {
                    $pendingTrx->push($this->buatOrder(
                        $cso, $drivers[$iDriver++ % $drivers->count()],
                        $zones[$iZona++ % $zones->count()],
                        'CashCSO', $tglPending->copy()->setTime(9 + $n * 3, 15), ++$nomorUrut
                    ));
                }
                $this->buatSetoran($cso, $pendingTrx, 'Pending', $admin);

                // --- 3. Setoran APPROVED (transaksi 'Settled') ---------------
                $approvedTrx = collect();
                foreach ([12, 11, 10] as $i => $mundur) {
                    $approvedTrx->push($this->buatOrder(
                        $cso, $drivers[$iDriver++ % $drivers->count()],
                        $zones[$iZona++ % $zones->count()],
                        'CashCSO', now()->subDays($mundur)->setTime(10 + $i, 0), ++$nomorUrut
                    ));
                }
                $this->buatSetoran($cso, $approvedTrx, 'Approved', $admin);

                $ringkasan[] = [
                    'cso'    => $cso->username,
                    'trx'    => $jmlOutstanding,
                    'hari'   => self::HARI_OUTSTANDING,
                    'total'  => $totalOutstanding,
                ];
            }
        });

        $this->command->info('Selesai. Tunai yang menunggu disetor per akun CSO:');
        foreach ($ringkasan as $r) {
            $this->command->info(sprintf(
                '  %-6s %2d transaksi / %d hari  =  Rp %s',
                $r['cso'], $r['trx'], $r['hari'], number_format($r['total'], 0, ',', '.')
            ));
        }
        $this->command->info('Tiap CSO juga punya 1 setoran Pending dan 1 setoran Approved di riwayat.');
        $this->command->warn('Hapus lagi dengan: php artisan db:seed --class=CsoDepositDummySeeder (idempoten), '
            . 'atau lihat method bersihkan() di berkas seeder ini.');
    }

    /**
     * Satu booking Completed beserta transaksinya.
     *
     * payout_status sengaja 'Paid': uang ke supir dianggap sudah beres, supaya
     * data contoh ini tidak ikut menumpuk antrean Pencairan Dana supir dan
     * yang tersisa untuk diuji hanya kewajiban setoran CSO.
     */
    private function buatOrder(
        User $cso, User $driver, Zone $zone, string $metode, $waktu, int $urut
    ): Transaction {
        $booking = Booking::create([
            'cso_id'          => $cso->id,
            'driver_id'       => $driver->id,
            'zone_id'         => $zone->id,
            'price'           => (float) $zone->price,
            'status'          => 'Completed',
            'passenger_phone' => self::DUMMY_PHONE_PREFIX . str_pad((string) $urut, 4, '0', STR_PAD_LEFT),
        ]);
        // created_at tidak fillable, jadi diisi setelah create.
        $booking->forceFill(['created_at' => $waktu, 'updated_at' => $waktu])->save();

        $trx = Transaction::create([
            'booking_id'    => $booking->id,
            'method'        => $metode,
            'amount'        => (float) $zone->price,
            'payout_status' => 'Paid',
            'payment_proof' => $metode === 'QRIS' ? 'payment_proofs/contoh.jpg' : null,
        ]);
        $trx->forceFill(['created_at' => $waktu, 'updated_at' => $waktu])->save();

        return $trx;
    }

    /**
     * Bentuk satu setoran beserta penandaan transaksinya, mengikuti persis
     * alur di CsoApiController::storeDeposit dan ApiController (persetujuan):
     * Pending -> transaksi 'Processing'; Approved -> transaksi 'Settled'.
     */
    private function buatSetoran(User $cso, $transaksi, string $status, ?User $admin): void
    {
        $tanggal = $transaksi
            ->map(fn (Transaction $t) => $t->created_at->toDateString())
            ->unique()->sort()->values()->all();

        $waktuAjukan = $transaksi->max('created_at')->copy()->addHours(2);

        $deposit = CsoDeposit::create([
            'cso_id'             => $cso->id,
            'amount'             => $transaksi->sum('amount'),
            'status'             => $status,
            'period_dates'       => $tanggal,
            'transactions_count' => $transaksi->count(),
            'note'               => self::DEPOSIT_NOTE_MARK . ' setoran contoh untuk uji coba.',
            'admin_note'         => $status === 'Approved' ? 'Uang diterima lengkap.' : null,
            'submitted_at'       => $waktuAjukan,
            'processed_at'       => $status === 'Approved' ? $waktuAjukan->copy()->addHours(5) : null,
            'processed_by'       => $status === 'Approved' ? $admin?->id : null,
        ]);
        $deposit->forceFill([
            'created_at' => $waktuAjukan,
            'updated_at' => $waktuAjukan,
        ])->save();

        Transaction::whereIn('id', $transaksi->pluck('id'))->update([
            'cso_deposit_id' => $deposit->id,
            'deposit_status' => $status === 'Approved' ? 'Settled' : 'Processing',
        ]);
    }

    /**
     * Hapus HANYA data contoh dari seeding sebelumnya.
     * Transaksi ikut terhapus lewat cascade FK booking_id.
     */
    protected function bersihkan(): array
    {
        $ids = Booking::where('passenger_phone', 'like', self::DUMMY_PHONE_PREFIX . '%')->pluck('id');
        $booking = 0;
        if ($ids->isNotEmpty()) {
            $booking = Booking::whereIn('id', $ids)->delete();
        }

        $setoran = CsoDeposit::where('note', 'like', self::DEPOSIT_NOTE_MARK . '%')->delete();

        return ['booking' => $booking, 'setoran' => $setoran];
    }
}
