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

class   DriverApiController extends Controller
{
    /**
     * Mengambil data utama untuk driver: info profil dan order aktif.
     */
    public function getProfile(Request $request)
    {
        // Gunakan nama fungsi relasinya: 'driverProfile'
        $driver = $request->user()->load('driverProfile'); 
        $activeBooking = null; 

        if ($driver->driverProfile?->status === 'ontrip') {
            
            $activeBooking = Booking::where('driver_id', $driver->id)
                ->whereNotIn('status', ['Completed', 'Cancelled']) 
                ->latest() 
                ->with('zoneTo:id,name')
                ->first();
        }

        // Kembalikan respons JSON yang normal
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
            // Jika ingin 'available' (masuk antrian), wajib kirim lat & lng
            'latitude'  => 'required_if:status,available|numeric',
            'longitude' => 'required_if:status,available|numeric',
        ]);

        $user = $request->user();

        // --- LOGIKA GEO-FENCING (Hanya jika mau ONLINE/Masuk Antrian) ---
        if ($validated['status'] === 'available') {
            
            // KOORDINAT BANDARA APT PRANOTO SAMARINDA (Sesuaikan titik presisinya)
            $airportLat = -0.372158; 
            $airportLng = 117.258153;
            
            // Jarak maksimal (Radius) dalam Kilometer
            $radiusKm = 2.0; 

            $driverLat = $request->latitude;
            $driverLng = $request->longitude;

            $distance = $this->calculateDistance($airportLat, $airportLng, $driverLat, $driverLng);

            // Tolak jika diluar radius
            if ($distance > $radiusKm) {
                return response()->json([
                    'message' => 'Anda berada di luar area Bandara (' . number_format($distance, 1) . ' km). Harap mendekat ke lokasi.',
                    'distance' => $distance
                ], 422); 
            }
        }

        // Update status jika lolos validasi
        $user->driverProfile()->update(['status' => $validated['status']]);

        return response()->json([
            'message' => 'Status berhasil diperbarui',
            'data' => $user->load('driverProfile')
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