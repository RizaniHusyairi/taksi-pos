<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CsoController;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PageController;



// Halaman Login
Route::get('/', [PageController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);

// Halaman yang butuh login
Route::middleware('auth')->group(function () {
    // Halaman CSO (hanya bisa diakses oleh role 'cso')
    Route::get('/cso', [PageController::class, 'showCso'])->middleware('role:cso');
    
    // Halaman Driver (hanya bisa diakses oleh role 'driver')
    Route::get('/driver', [PageController::class, 'showDriver'])->middleware('role:driver');
    
    // Halaman Admin (hanya bisa diakses oleh role 'admin')
    Route::get('/admin', [PageController::class, 'showAdmin'])->middleware('role:admin');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/zones', [CsoController::class, 'getZones']);
    Route::get('/drivers/available', [CsoController::class, 'getAvailableDrivers']);
    Route::post('/bookings', [CsoController::class, 'storeBooking']);
    Route::post('/transactions', [CsoController::class, 'storeTransaction']);
    // ... rute lainnya
});
