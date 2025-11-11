<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\Withdrawal;
use App\Models\Setting;

class DriverApiController extends Controller
{
    /**
     * Mengambil data utama untuk driver: info profil dan order aktif.
     */
    // app/Http/Controllers/Api/DriverApiController.php

// app/Http/Controllers/Api/DriverApiController.php

public function getProfile(Request $request)
{
    $driver = $request->user()->load('driver_profile');
    
    // LANGKAH 1: Verifikasi status driver yang didapat server
    $driverStatus = $driver->driver_profile?->status;

    if ($driverStatus !== 'ontrip') {
        // Jika kode berhenti di sini, berarti status driver di database BUKAN 'ontrip'
        dd([
            'DEBUGGING_RESULT' => 'LANGKAH 1 GAGAL',
            'MESSAGE' => 'Server melihat status driver Anda bukan ontrip.',
            'STATUS_DITEMUKAN' => $driverStatus,
        ]);
    }

    // LANGKAH 2: Jalankan query dan lihat hasilnya
    $activeBooking = Booking::where('driver_id', $driver->id)
        ->whereNotIn('status', ['Completed', 'Cancelled']) // Ini akan mencari status 'Assigned', 'Paid', dll.
        ->latest()
        ->with('zoneTo:id,name')
        ->first();

    // LANGKAH 3: Hentikan eksekusi dan tampilkan semua yang ditemukan
    // Ini adalah hasil akhir yang dilihat oleh server sebelum dikirim.
    dd([
        'DEBUGGING_RESULT' => 'LANGKAH 2 & 3 BERHASIL',
        'MESSAGE' => 'Ini adalah data yang ditemukan oleh server.',
        'DRIVER_STATUS' => $driverStatus,
        'BOOKING_YANG_DITEMUKAN' => $activeBooking, // <-- Perhatikan nilai ini!
    ]);

    // Kode di bawah ini tidak akan dijalankan karena ada dd()
    $driver->active_booking = $activeBooking;
    return response()->json($driver);
}
    /**
     * Mengubah status driver (available/offline).
     */
    public function setStatus(Request $request)
    {
        $validated = $request->validate([
        'status' => 'required|in:available,offline',
    ]);

    $user = $request->user();
    $user->driverProfile()->update(['status' => $validated['status']]);

    // Kembalikan data user yang sudah di-update beserta profilnya
    return response()->json($user->load('driverProfile'));
    }

    /**
     * Menyelesaikan perjalanan.
     */
    public function completeBooking(Request $request, Booking $booking)
    {
        // Pastikan driver yang menyelesaikan adalah driver dari booking tersebut
        if ($request->user()->id !== $booking->driver_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        DB::transaction(function () use ($booking) {
            $booking->update(['status' => 'Completed']);
            $booking->driver->driverProfile()->update(['status' => 'available']);
        });
    // === PERBAIKAN DI SINI ===
        // Setelah menyelesaikan booking, panggil metode getProfile
        // untuk mendapatkan dan mengembalikan data terbaru.
        return $this->getProfile($request);
        
    }

    /**
     * Mengambil saldo dompet driver.
     */
    public function getBalance(Request $request)
    {
        $driver = $request->user();
        $commissionRate = (float) Setting::where('key', 'commission_rate')->value('value');

        $totalCredits = Transaction::whereHas('booking', function ($query) use ($driver) {
                $query->where('driver_id', $driver->id);
            })
            ->whereIn('method', ['QRIS', 'CashCSO'])
            ->sum('amount');
        
        $driverShare = $totalCredits * (1 - ($commissionRate / 100)); // asumsi komisi disimpan sbg 20 bukan 0.2

        $totalDebits = $driver->withdrawals()
            ->whereIn('status', ['Approved', 'Paid'])
            ->sum('amount');
            
        return response()->json(['balance' => round($driverShare - $totalDebits)]);
    }
    
    /**
     * Mengajukan penarikan dana.
     */
    public function requestWithdrawal(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:10000',
        ]);
        
        // Hitung saldo lagi untuk validasi
        $balance = $this->getBalance($request)->getData()->balance;

        if ($validated['amount'] > $balance) {
            return response()->json(['message' => 'Jumlah penarikan melebihi saldo yang tersedia.'], 422);
        }

        $request->user()->withdrawals()->create([
            'amount' => $validated['amount'],
            'status' => 'Pending',
            'requested_at' => now(),
        ]);

        return response()->json(['message' => 'Withdrawal request submitted.'], 201);
    }
    
    /**
     * Mengambil riwayat penarikan dana.
     */
    public function getWithdrawalHistory(Request $request)
    {
        $withdrawals = $request->user()->withdrawals()
            ->orderBy('requested_at', 'desc')
            ->get();
        return response()->json($withdrawals);
    }

    /**
     * Mengambil riwayat perjalanan (transaksi).
     */
    public function getTripHistory(Request $request)
    {
        $query = Transaction::whereHas('booking', function ($q) use ($request) {
            $q->where('driver_id', $request->user()->id);
        })->with('booking.zoneTo:id,name');

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }
        
        $history = $query->orderBy('created_at', 'desc')->get();
        return response()->json($history);
    }
}