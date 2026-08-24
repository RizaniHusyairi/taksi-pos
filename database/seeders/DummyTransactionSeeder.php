<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data contoh transaksi untuk SEMUA metode pembayaran, tersebar ke seluruh
 * akun CSO dan supir yang ada. Dipakai untuk menguji laporan, dashboard,
 * grafik performa, dan perhitungan hutang setoran supir.
 *
 * Jalankan:  php artisan db:seed --class=DummyTransactionSeeder
 *
 * AMAN DIULANG. Setiap baris buatan seeder ini ditandai nomor penumpang
 * berawalan [DUMMY_PHONE_PREFIX]; saat dijalankan ulang, HANYA baris bertanda
 * itu yang dihapus lebih dulu — data asli tidak pernah tersentuh.
 *
 * Untuk membersihkannya tanpa menyeed ulang, lihat perintah di akhir berkas.
 */
class DummyTransactionSeeder extends Seeder
{
    /** Penanda data contoh. Nomor asli tidak akan memakai awalan ini. */
    public const DUMMY_PHONE_PREFIX = '0899000';

    public function run(): void
    {
        $csos = User::where('role', 'cso')->orderBy('id')->get();
        $drivers = User::where('role', 'driver')->orderBy('id')->get();
        $zones = Zone::orderBy('id')->get();

        if ($csos->isEmpty() || $drivers->isEmpty() || $zones->isEmpty()) {
            $this->command->error('Butuh minimal 1 CSO, 1 supir, dan 1 zona. Jalankan UserSeeder & ZoneSeeder dulu.');
            return;
        }

        $dihapus = $this->bersihkan();
        if ($dihapus > 0) {
            $this->command->info("Menghapus {$dihapus} booking contoh dari seeding sebelumnya.");
        }

        // Satu "resep" per skenario. Sengaja mencakup ketiga metode bayar plus
        // order manual tanpa zona, order yang masih berjalan, dan yang batal —
        // supaya laporan Performa CSO punya angka Selesai vs Dibatalkan yang nyata.
        $resep = [
            // metode,      status booking, payout,       zona?, jumlah per CSO
            ['QRIS',        'Completed',    'Paid',       true,  4],
            ['QRIS',        'Paid',         'Unpaid',     true,  2],
            ['CashCSO',     'Completed',    'Paid',       true,  4],
            ['CashCSO',     'Paid',         'Unpaid',     true,  2],
            ['CashDriver',  'Completed',    'Unpaid',     true,  3], // → hutang setoran supir
            ['CashDriver',  'CashDriver',   'Unpaid',     true,  2], // dibayar ke supir, trip belum tuntas
            ['CashDriver',  'Completed',    'Unpaid',     false, 2], // order manual (tanpa zona) → fee flat
            [null,          'Assigned',     null,         true,  2], // masih berjalan, belum ada transaksi
            [null,          'Cancelled',    null,         true,  1], // batal
        ];

        $iDriver = 0;
        $iZona = 0;
        $jmlBooking = 0;
        $jmlTransaksi = 0;
        $perMetode = ['QRIS' => 0, 'CashCSO' => 0, 'CashDriver' => 0];
        $nomorUrut = 0;

        DB::transaction(function () use (
            $csos, $drivers, $zones, $resep,
            &$iDriver, &$iZona, &$jmlBooking, &$jmlTransaksi, &$perMetode, &$nomorUrut
        ) {
            foreach ($csos as $cso) {
                foreach ($resep as [$metode, $statusBooking, $payout, $pakaiZona, $jumlah]) {
                    for ($n = 0; $n < $jumlah; $n++) {
                        // Supir & zona dirotasi merata agar setiap akun kebagian
                        // dan papan peringkat tidak menumpuk di satu orang.
                        $driver = $drivers[$iDriver++ % $drivers->count()];
                        $zone = $pakaiZona ? $zones[$iZona++ % $zones->count()] : null;

                        // Sebar ke 60 hari terakhir supaya filter bulanan di
                        // Performa CSO & Laporan Pendapatan ada isinya.
                        $waktu = now()->subDays(random_int(0, 59))
                            ->setTime(random_int(6, 21), random_int(0, 59));

                        $harga = $zone
                            ? (float) $zone->price
                            : (float) (random_int(5, 20) * 10000); // tarif manual

                        $booking = Booking::create([
                            'cso_id' => $cso->id,
                            'driver_id' => $driver->id,
                            'zone_id' => $zone?->id,
                            'price' => $harga,
                            'status' => $statusBooking,
                            'manual_destination' => $zone ? null : 'Tujuan manual (data contoh)',
                            'passenger_phone' => self::DUMMY_PHONE_PREFIX . str_pad((string) (++$nomorUrut), 3, '0', STR_PAD_LEFT),
                        ]);
                        // created_at diisi setelah create: kolom itu tidak fillable.
                        $booking->forceFill(['created_at' => $waktu, 'updated_at' => $waktu])->save();
                        $jmlBooking++;

                        if ($metode === null) {
                            continue; // Assigned & Cancelled memang tanpa transaksi
                        }

                        $trx = Transaction::create([
                            'booking_id' => $booking->id,
                            'method' => $metode,
                            'amount' => $harga,
                            'payout_status' => $payout,
                            // Bukti bayar hanya relevan untuk QRIS.
                            'payment_proof' => $metode === 'QRIS' ? 'payment_proofs/contoh.jpg' : null,
                        ]);
                        $trx->forceFill(['created_at' => $waktu, 'updated_at' => $waktu])->save();

                        $jmlTransaksi++;
                        $perMetode[$metode]++;
                    }
                }
            }
        });

        $this->command->info("Selesai: {$jmlBooking} booking, {$jmlTransaksi} transaksi.");
        $this->command->info(
            "Per metode → QRIS: {$perMetode['QRIS']}, CashCSO: {$perMetode['CashCSO']}, CashDriver: {$perMetode['CashDriver']}"
        );
        $this->command->info("Tersebar ke {$csos->count()} CSO dan {$drivers->count()} supir, 60 hari terakhir.");
        $this->command->warn('CashDriver berstatus Unpaid = hutang setoran supir. Ini akan muncul di saldo & pencairan.');
    }

    /**
     * Hapus data contoh dari seeding sebelumnya (dan HANYA itu).
     * Transaksi ikut terhapus lewat cascade FK booking_id.
     */
    protected function bersihkan(): int
    {
        $ids = Booking::where('passenger_phone', 'like', self::DUMMY_PHONE_PREFIX . '%')->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        Transaction::whereIn('booking_id', $ids)->delete();
        return Booking::whereIn('id', $ids)->delete();
    }
}

/*
 * Membersihkan tanpa menyeed ulang:
 *
 *   php artisan tinker --execute="\App\Models\Transaction::whereIn('booking_id',
 *     \App\Models\Booking::where('passenger_phone','like','0899000%')->pluck('id'))->delete();
 *     \App\Models\Booking::where('passenger_phone','like','0899000%')->delete();"
 */
