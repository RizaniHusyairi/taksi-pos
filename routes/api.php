<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ApiController; // <-- Arahkan ke ApiController
use App\Http\Controllers\Api\CsoApiController; // <-- Arahkan ke CsoApiController

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// === Rute API untuk Aplikasi POS Taksi ===
Route::middleware('auth:sanctum')->group(function() {

    // --- Rute untuk CSO & Admin ---
    Route::get('/zones', [ApiController::class, 'getZones']);
    Route::get('/drivers/available', [ApiController::class, 'getAvailableDrivers']);
    Route::post('/bookings', [ApiController::class, 'storeBooking']);
    Route::post('/payment', [ApiController::class, 'recordPayment']);

    // --- Rute Khusus untuk Driver ---
    Route::get('/driver/balance', [ApiController::class, 'getDriverBalance']);
    Route::post('/driver/status', [ApiController::class, 'setDriverStatus']);
    
    // Rute untuk menyelesaikan booking. {booking} adalah parameter dinamis (ID booking)
    // Ini menggunakan fitur canggih Laravel bernama "Route Model Binding"
    Route::post('/bookings/{booking}/complete', [ApiController::class, 'completeBooking']);

    // Tambahkan rute untuk withdrawal, dll. di sini nanti


     // =========================================================
    // === RUTE BARU: Khusus untuk Panel Admin ===
    // =========================================================
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        // Dashboard
        Route::get('/dashboard-stats', [ApiController::class, 'adminGetDashboardStats']);
        Route::get('/stats', [ApiController::class, 'getAdminStats']);
        Route::get('/charts', [ApiController::class, 'getAdminCharts']);

        // Manajemen Zona
        Route::get('/zones', [ApiController::class, 'adminGetZones']);
        Route::post('/zones', [ApiController::class, 'adminStoreZone']);
        Route::put('/zones/{zone}', [ApiController::class, 'adminUpdateZone']);
        Route::delete('/zones/{zone}', [ApiController::class, 'adminDestroyZone']);

        // Manajemen Pengguna
        Route::get('/users', [ApiController::class, 'adminGetUsers']);
        Route::post('/users', [ApiController::class, 'adminStoreUser']);
        Route::put('/users/{user}', [ApiController::class, 'adminUpdateUser']);
        Route::post('/users/{user}/toggle-status', [ApiController::class, 'adminToggleUserStatus']); // Untuk aktivasi/deaktivasi
        Route::get('/users/role/{role}', [ApiController::class, 'adminGetUsersByRole']);

        // Manajemen Keuangan
        Route::get('/transactions', [ApiController::class, 'adminGetTransactions']);
        Route::get('/withdrawals', [ApiController::class, 'adminGetWithdrawals']);
        Route::post('/withdrawals/{withdrawal}/approve', [ApiController::class, 'adminApproveWithdrawal']);
        Route::post('/withdrawals/{withdrawal}/reject', [ApiController::class, 'adminRejectWithdrawal']);
        
        // Laporan
        Route::get('/reports/revenue', [ApiController::class, 'adminGetRevenueReport']);
        Route::get('/reports/driver-performance', [ApiController::class, 'adminGetDriverPerformanceReport']);

        // Pengaturan
        Route::get('/settings', [ApiController::class, 'adminGetSettings']);
        Route::post('/settings', [ApiController::class, 'adminUpdateSettings']);



    });


    // =========================================================
    // === RUTE BARU: Khusus untuk Panel CSO ===
    // =========================================================
    Route::prefix('cso')->middleware('role:cso')->group(function () {
        // Mengambil data awal yang dibutuhkan panel
        Route::get('/zones', [CsoApiController::class, 'getZones']);
        Route::get('/available-drivers', [CsoApiController::class, 'getAvailableDrivers']);

        // Aksi membuat booking dan pembayaran
        Route::post('/bookings', [CsoApiController::class, 'storeBooking']);
        Route::post('/payment', [CsoApiController::class, 'recordPayment']);

        // Mengambil riwayat transaksi untuk CSO yang login
        Route::get('/history', [CsoApiController::class, 'getHistory']);
    });
});