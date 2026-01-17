<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ApiController; // <-- Arahkan ke ApiController
use App\Http\Controllers\Api\CsoApiController; // <-- Arahkan ke CsoApiController
use App\Http\Controllers\Api\DriverApiController; // <-- Arahkan ke DriverApiController

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});




// === Rute API untuk Aplikasi POS Taksi ===
Route::middleware('auth:sanctum')->group(function() {

    
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
        Route::post('/withdrawals/{withdrawal}/paid', [ApiController::class, 'adminMarkAsPaid']);
        Route::get('/withdrawals/{withdrawal}/details', [ApiController::class, 'adminGetWithdrawalDetails']);
        
        // Laporan
        Route::get('/reports/revenue', [ApiController::class, 'adminGetRevenueReport']);
        Route::get('/reports/driver-performance', [ApiController::class, 'adminGetDriverPerformanceReport']);

        // Pengaturan
        Route::get('/settings', [ApiController::class, 'adminGetSettings']);
        Route::post('/settings', [ApiController::class, 'adminUpdateSettings']);

        Route::get('/queue', [ApiController::class, 'adminGetQueue']);
        Route::post('/queue/remove', [ApiController::class, 'adminRemoveFromQueue']); // Post userId via body or url param logic
        Route::delete('/queue/{userId}', [ApiController::class, 'adminRemoveFromQueue']); // Restful style
        Route::post('/queue/move', [ApiController::class, 'adminMoveQueue']);
        Route::post('/queue/line-number', [ApiController::class, 'adminUpdateLineNumber']);




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

        Route::post('/process-order', [CsoApiController::class, 'processOrder']);

        // Route Profile CSO
        Route::get('/profile', [CsoApiController::class, 'getProfile']);
        Route::post('/profile/update', [CsoApiController::class, 'updateProfile']);
        Route::post('/profile/password', [CsoApiController::class, 'changePassword']);

    });
    
    
    // =========================================================
    // === RUTE BARU: Khusus untuk Panel Supir ===
    // =========================================================
    Route::prefix('driver')->middleware('role:driver')->group(function () {
        // Data utama: profil, status, dan order aktif
        Route::get('/profile', [DriverApiController::class, 'getProfile']);
        
        // Aksi-aksi
        Route::post('/status', [DriverApiController::class, 'setStatus']);
        Route::post('/bookings/{booking}/complete', [DriverApiController::class, 'completeBooking']);
        Route::post('/bookings/{booking}/start', [DriverApiController::class, 'startBooking']);
        
        // Fitur Dompet (Wallet)
        Route::get('/balance', [DriverApiController::class, 'getBalance']);
        Route::get('/withdrawals', [DriverApiController::class, 'getWithdrawalHistory']);
        Route::post('/withdrawals', [DriverApiController::class, 'requestWithdrawal']);
        
        // Riwayat Perjalanan
        Route::get('/history', [DriverApiController::class, 'getTripHistory']);
        Route::post('/location', [DriverApiController::class, 'updateLocation']);

        Route::post('/bank-details', [DriverApiController::class, 'updateBankDetails']);
        Route::post('/change-password', [DriverApiController::class, 'changePassword']);

        Route::post('/profile/update', [DriverApiController::class, 'updateProfile']);
    });
});