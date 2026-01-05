<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CsoController;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PageController;
use App\Models\Setting;
use App\Models\DriverQueue;
use App\Models\DriverProfile;



// Halaman Login
Route::get('/', [PageController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/init-rotation', function () {
    // Set giliran awal mulai dari L1
    Setting::updateOrCreate(
        ['key' => 'daily_start_line'],
        ['value' => 1] 
    );
    return "Rotasi berhasil diinisialisasi ke Nomor 1.";
});

Route::get('/reset-daily-queue', function () {
    // 1. Kosongkan antrian fisik
    DriverQueue::truncate();
    
    // 2. Reset tanggal masuk terakhir semua driver agar dianggap "Baru Masuk" lagi
    DriverProfile::query()->update([
        'last_queue_date' => null, 
        'status' => 'offline'
    ]);
    
    return "Antrian Harian Berhasil Direset. Silakan tes ulang dari awal.";
});

// Halaman yang butuh login
Route::middleware('auth')->group(function () {
    // Halaman CSO (hanya bisa diakses oleh role 'cso')
    Route::get('/cso', [PageController::class, 'showCso'])->middleware('role:cso');
    
    // Halaman Driver (hanya bisa diakses oleh role 'driver')
    Route::get('/driver', [PageController::class, 'showDriver'])->middleware('role:driver');
    
    // Halaman Admin (hanya bisa diakses oleh role 'admin')
    Route::get('/admin', [PageController::class, 'showAdmin'])->middleware('role:admin');
});
