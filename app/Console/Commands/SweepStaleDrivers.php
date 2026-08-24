<?php

namespace App\Console\Commands;

use App\Models\DriverActivity;
use App\Models\DriverProfile;
use App\Models\DriverQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keluarkan supir 'standby' yang HP-nya berhenti mengirim lokasi.
 *
 * Kenapa perlu: seluruh mesin antrian digerakkan oleh ping dari aplikasi —
 * penanda di luar area, tenggang, sampai auto-keluar semuanya baru bekerja
 * kalau ada ping yang masuk. Artinya supir yang MEMATIKAN aplikasi lalu pulang
 * terlihat persis sama dengan supir yang setia menunggu di bandara: ia tetap
 * memegang gilirannya dan tetap kebagian order. Perintah ini menutup celah itu
 * dari sisi server, tanpa bergantung pada kerja sama aplikasi.
 *
 * Aplikasi mengirim heartbeat tiap 75 detik (lihat background_service.dart),
 * jadi ambang menit-an di config sangat longgar — yang tersapu hanyalah HP
 * yang benar-benar diam: mati, force-stop, atau dibunuh sistem.
 *
 * Supir yang tersapu TIDAK diblokir auto-join: ia tidak memilih pergi, jadi
 * saat aplikasinya hidup lagi di area bandara ia otomatis masuk lagi lewat
 * grup rejoin (antrian belakang) — sama seperti supir yang baru datang.
 */
class SweepStaleDrivers extends Command
{
    protected $signature = 'queue:sweep-stale';

    protected $description = 'Keluarkan supir standby yang berhenti mengirim lokasi dari antrian';

    public function handle()
    {
        $menit = (int) config('taksi.driver_queue.stale_location_minutes');

        // 0 / negatif = penyapu sengaja dimatikan.
        if ($menit <= 0) {
            $this->info('Penyapu nonaktif (stale_location_minutes <= 0).');
            return self::SUCCESS;
        }

        $batas = now()->subMinutes($menit);

        // location_updated_at NULL ikut tersapu: tidak ada satu pun bukti
        // kehadiran. Jalur join manual & auto-join sama-sama mengisi kolom ini,
        // jadi supir yang benar-benar hadir tidak akan pernah bernilai null.
        $basi = DriverProfile::where('status', 'standby')
            ->where(function ($q) use ($batas) {
                $q->whereNull('location_updated_at')
                  ->orWhere('location_updated_at', '<', $batas);
            })
            ->get();

        if ($basi->isEmpty()) {
            $this->info('Tidak ada supir basi.');
            return self::SUCCESS;
        }

        foreach ($basi as $profile) {
            $terakhir = $profile->location_updated_at
                ? $profile->location_updated_at->diffForHumans()
                : 'belum pernah';

            DriverQueue::where('user_id', $profile->user_id)->delete();
            $profile->update([
                'status'            => 'offline',
                'out_of_area_since' => null,
            ]);

            DriverActivity::create([
                'user_id'     => $profile->user_id,
                'activity_type' => 'QUEUE_LEAVE_STALE',
                'description' => "Dikeluarkan dari antrian (lokasi tidak terkirim > {$menit} menit; terakhir: {$terakhir})",
            ]);

            $this->line("  - user #{$profile->user_id} dikeluarkan (terakhir kirim: {$terakhir})");
        }

        $jumlah = $basi->count();
        Log::info("queue:sweep-stale mengeluarkan {$jumlah} supir basi (> {$menit} menit).");
        $this->info("{$jumlah} supir dikeluarkan dari antrian.");

        return self::SUCCESS;
    }
}
