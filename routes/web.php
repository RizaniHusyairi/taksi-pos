<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PublicReceiptController;
use App\Http\Controllers\ExportController;



// Halaman Login
Route::get('/', [PageController::class, 'showLogin'])->name('login');
// Rem laju login web: 5 percobaan gagal per menit per IP+username. Tanpa ini
// password 6 karakter (batas minimum di ganti-password) bisa ditebak massal.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Penilaian penumpang: satu struk sekali nilai, tapi tetap perlu rem agar
// token tidak bisa dibombardir.
Route::get('/receipt/{uuid}', [PublicReceiptController::class, 'show'])->name('receipt.show');
Route::post('/receipt/{uuid}/rate', [PublicReceiptController::class, 'rate'])
    ->middleware('throttle:10,1')
    ->name('receipt.rate');


// Halaman yang butuh login
Route::middleware('auth')->group(function () {
    // Halaman CSO & Driver web sudah dihapus — keduanya hanya lewat aplikasi
    // mobile. Login web untuk role 'cso' dan 'driver' ditolak di AuthController.

    // Halaman Admin (hanya bisa diakses oleh role 'admin')
    Route::get('/admin', [PageController::class, 'showAdmin'])->middleware('role:admin');
    
    // PDF Export Route (Admin Only)
    Route::get('/admin/withdrawals/{id}/export', [ExportController::class, 'exportWithdrawalById'])->middleware('role:admin');

    // Export Log Transaksi (Admin Only) — pakai filter dari query string
    Route::get('/admin/transactions/export/pdf', [ExportController::class, 'transactionsPdf'])->middleware('role:admin');
    Route::get('/admin/transactions/export/excel', [ExportController::class, 'transactionsExcel'])->middleware('role:admin');

    // Export Laporan Pendapatan (Admin Only) — rentang tanggal dari query string
    Route::get('/admin/reports/revenue/export/pdf', [ExportController::class, 'revenuePdf'])->middleware('role:admin');
    Route::get('/admin/reports/revenue/export/excel', [ExportController::class, 'revenueExcel'])->middleware('role:admin');
});
