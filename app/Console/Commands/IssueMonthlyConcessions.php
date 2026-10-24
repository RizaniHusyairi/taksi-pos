<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ConcessionService;
use Illuminate\Console\Command;

/**
 * Terbitkan tagihan konsesi bulan berjalan untuk semua supir aktif.
 * Idempoten — aman dijalankan ulang. ConcessionService::current() juga
 * menerbitkan tagihan secara lazy, jadi perintah ini pelengkap, bukan
 * satu-satunya jalur.
 */
class IssueMonthlyConcessions extends Command
{
    protected $signature = 'concession:issue';

    protected $description = 'Terbitkan tagihan konsesi bulan ini untuk semua supir aktif';

    public function handle(ConcessionService $konsesi): int
    {
        $jumlah = 0;

        User::where('role', 'driver')->where('active', true)
            ->each(function (User $d) use ($konsesi, &$jumlah) {
                if ($c = $konsesi->issue($d)) {
                    $jumlah += $c->wasRecentlyCreated ? 1 : 0;
                }
            });

        $this->info("Tagihan konsesi baru: {$jumlah}");

        return self::SUCCESS;
    }
}
