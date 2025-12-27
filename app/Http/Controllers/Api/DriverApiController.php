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
use App\Models\DriverQueue;

class   DriverApiController extends Controller
{

    /**
     * Helper untuk menyuntikkan "Status Virtual" ke objek user
     * agar Frontend JS tidak error.
     */
    private function attachVirtualStatus($user)
    {
        // Cek apakah user ada di tabel antrian
        $isInQueue = DriverQueue::where('user_id', $user->id)->exists();
        
        // Buat properti dinamis 'status' di driverProfile
        // Frontend tahunya: 'available' (Online) atau 'offline'
        $user->driverProfile->status = $isInQueue ? 'available' : 'offline';
        
        return $user;
    }


    /**
     * Mengambil data utama untuk driver: info profil dan order aktif.
     */
    public function getProfile(Request $request)
    {
        // Load relasi profile
        $driver = $request->user()->load('driverProfile');

        // Suntikkan status virtual (berdasarkan tabel queue)
        $driver = $this->attachVirtualStatus($driver);

        // Cek Booking Aktif (Logika lama)
        $activeBooking = null;
        // Kita anggap jika sedang 'ontrip' itu didapat dari Booking yang belum selesai
        // Bukan dari status profile lagi.
        $ongoingBooking = Booking::where('driver_id', $driver->id)
            ->whereNotIn('status', ['Completed', 'Cancelled', 'Paid', 'CashDriver']) // Sesuaikan status akhirmu
            ->latest()
            ->with('zoneTo:id,name')
            ->first();

        if ($ongoingBooking) {
            $driver->driverProfile->status = 'ontrip'; // Override status jadi ontrip jika ada order
            $activeBooking = $ongoingBooking;
        }

        $driver->active_booking = $activeBooking;
        
        return response()->json($driver);
    }
    /**
     * Mengubah status driver (available/offline).
     */
    public function setStatus(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|in:join,leave', // Frontend kirim 'join' atau 'leave'
            'latitude'  => 'required_if:action,join|numeric',
            'longitude' => 'required_if:action,join|numeric',
        ]);

        $user = $request->user();

        if ($validated['action'] === 'join') {
            // --- LOGIKA MASUK ANTRIAN ---
            
            // 1. Cek Jarak (Geo-fencing)
            $airportLat = -0.372158; 
            $airportLng = 117.258153;
            $distance = $this->calculateDistance($airportLat, $airportLng, $request->latitude, $request->longitude);

            if ($distance > 2.0) { // Toleransi 2 KM
                return response()->json([
                    'message' => 'Anda berada di luar area Bandara (' . number_format($distance, 1) . ' km).',
                ], 422);
            }

            // 2. Cek Duplikasi (Idempotency)
            // Jika sudah ada, biarkan saja sukses (biar frontend sinkron)
            DriverQueue::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    // created_at otomatis terisi, menjadi penentu nomor urut
                ]
            );

            $msg = 'Berhasil masuk antrian';

        } else {
            // --- LOGIKA KELUAR ANTRIAN ---
            DriverQueue::where('user_id', $user->id)->delete();
            $msg = 'Berhasil keluar antrian';
        }

        // Kembalikan data user terbaru dengan status virtualnya
        // agar UI tombol langsung berubah
        $user->load('driverProfile');
        $userWithStatus = $this->attachVirtualStatus($user);

        return response()->json([
            'message' => $msg,
            'data' => $userWithStatus
        ]);
    }


    /**
     * Fungsi helper menghitung jarak dua titik koordinat (Haversine Formula)
     * Return dalam Kilometer
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2) {
        $earthRadius = 6371; 

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon/2) * sin($dLon/2);

        $c = 2 * atan2(sqrt($a), sqrt(1-$a));

        return $earthRadius * $c;
    }

    /**
     * Menyelesaikan perjalanan.
     */
    public function completeBooking(Request $request, Booking $booking)
    {
        if ($request->user()->id !== $booking->driver_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Update status booking saja. 
        // Tidak perlu update status driver_profile (karena kolomnya sudah dihapus).
        // Driver otomatis jadi 'offline' (tidak di queue) setelah trip selesai.
        $booking->update(['status' => 'Completed']);
        
        // Panggil getProfile untuk mengembalikan data terbaru ke frontend
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
        
        $driverShare = $totalCredits * (1 - $commissionRate); // asumsi komisi disimpan sbg 20 bukan 0.2

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