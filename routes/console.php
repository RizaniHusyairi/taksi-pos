<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('queue:rotate')->dailyAt('00:00');

// Backup database harian (mysqldump untuk MySQL), simpan 14 backup terbaru.
Schedule::command('backup:db')->dailyAt('02:00');

// Pangkas jejak lokasi supir lebih tua dari 3 hari (cegah tabel membengkak).
Schedule::call(function () {
    \App\Models\DriverLocationLog::where('recorded_at', '<', now()->subDays(3))->delete();
})->dailyAt('03:00');