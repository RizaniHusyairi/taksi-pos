<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Setting;
use App\Models\DriverProfile;
use App\Models\DriverQueue;
use Illuminate\Support\Facades\Log;

class DailyQueueRotation extends Command
{
    /**
     * Nama perintah untuk dijalankan di terminal
     */
    protected $signature = 'queue:rotate';

    /**
     * Keterangan perintah
     */
    protected $description = 'Memutar nomor urut prioritas driver dan mereset antrian harian';

    /**
     * Eksekusi logika
     */
    public function handle()
    {
        // 1. Hitung Rotasi Angka Giliran (Sama seperti sebelumnya)
        $currentStart = (int) Setting::where('key', 'daily_start_line')->value('value') ?: 1;
        $maxLine = DriverProfile::max('line_number') ?? 30;
        $newStart = $currentStart + 1;
        if ($newStart > $maxLine) $newStart = 1;

        Setting::updateOrCreate(['key' => 'daily_start_line'], ['value' => $newStart]);

        // 2. BERSIHKAN ANTRIAN LAMA
        DriverQueue::truncate();

        // 3. MASUKKAN SEMUA DRIVER KE ANTRIAN (PRE-FILL)
        $profiles = DriverProfile::whereNotNull('line_number')->get();
        $today = now()->toDateString();

        foreach ($profiles as $profile) {
            // Hitung Skor Prioritas (Rumus Rotasi)
            $myLine = $profile->line_number;
            $sortOrder = 0;

            if ($myLine >= $newStart) {
                $sortOrder = $myLine - $newStart;
            } else {
                $sortOrder = ($maxLine - $newStart) + $myLine;
            }

            // Masukkan ke tabel Queue
            // Lat/Long kita kosongkan dulu (atau 0) karena mereka belum datang
            DriverQueue::create([
                'user_id' => $profile->user_id,
                'latitude' => 0,
                'longitude' => 0,
                'sort_order' => $sortOrder
            ]);

            // Reset Status Profil jadi Offline & Reset Tanggal Masuk
            // Kita reset last_queue_date agar besoknya dianggap fresh lagi
            $profile->update([
                'status' => 'offline',
                'last_queue_date' => null 
            ]);
        }

        $this->info("Rotasi Selesai. Giliran dimulai dari L{$newStart}. Semua driver telah didaftarkan ke antrian.");
    }
}