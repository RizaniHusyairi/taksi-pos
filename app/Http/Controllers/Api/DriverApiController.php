<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\Withdrawals;
use App\Models\Setting;
use App\Models\DriverQueue;

class DriverApiController extends Controller
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
        // --- PERBAIKAN DI SINI ---
        $ongoingBooking = Booking::where('driver_id', $driver->id)
            // HAPUS 'Paid' dan 'CashDriver' dari daftar pengecualian (whereNotIn)
            // Kita hanya ingin menyembunyikan order yang sudah Selesai (Completed) atau Dibatalkan (Cancelled)
            ->whereNotIn('status', ['Completed', 'Cancelled']) 
            ->latest()
            ->with('zoneTo:id,name')
            ->first();
        

        $driver->active_booking = $ongoingBooking;
        
        return response()->json($driver);
    }
    /**
     * Mengubah status driver (available/offline).
     */
    public function setStatus(Request $request)
    {
        // ... (Validasi tetap sama) ...
        $validated = $request->validate([
            'action' => 'required|in:join,leave',
            'latitude'  => 'required_if:action,join|numeric',
            'longitude' => 'required_if:action,join|numeric',
            'reason'             => 'required_if:action,leave|in:self,other',
            'manual_destination' => 'required_if:reason,self|string|nullable',
            'manual_price'       => 'required_if:reason,self|numeric|min:0',
        ]);

        $user = $request->user();

        $profile = $user->driverProfile;

        if ($validated['action'] === 'join') {
            // ... (Logika Join Tetap Sama) ...
            $airportLat = -0.419266; 
            $airportLng = 117.255554;
            $distance = $this->calculateDistance($airportLat, $airportLng, $request->latitude, $request->longitude);

            // Ganti 2.0 dengan 10000.0 untuk testing
            if ($distance > 10000.0) { 
                return response()->json(['message' => 'Terlalu jauh dari bandara.'], 422);
            }

            $sortOrder = 1000; // Default untuk Re-join (Antrian Belakang)
            $today = now()->toDateString();
            
            // Cek apakah ini pertama kali masuk hari ini?
            // Syarat: Tanggal terakhir masuk BUKAN hari ini
            $isFirstJoinToday = ($profile->last_queue_date !== $today);

            if ($isFirstJoinToday && $profile->line_number) {
                // INI ADALAH FIRST JOIN -> HITUNG PRIORITAS BERDASARKAN ROTASI
                
                // Ambil Angka Giliran Hari Ini (Default 1 jika error)
                $dailyStart = (int) Setting::where('key', 'daily_start_line')->value('value') ?: 1;
                $myLine = $profile->line_number;
                $totalDrivers = 30; // Atau hitung dinamis: DriverProfile::max('line_number');

                // Rumus Matematika Rotasi
                if ($myLine >= $dailyStart) {
                    // Kasus Normal: Giliran 5, Saya 6. Posisi = 6 - 5 = 1 (Urutan ke-2 karena index 0)
                    $sortOrder = $myLine - $dailyStart;
                } else {
                    // Kasus Wrapping: Giliran 28, Saya 2. Saya harus di bawah 30.
                    // Posisi = (30 - 28) + 2 = 4.
                    $sortOrder = ($totalDrivers - $dailyStart) + $myLine;
                }
            }

            DriverQueue::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'latitude' => $request->latitude, 
                'longitude' => $request->longitude, 
                'sort_order' => $sortOrder]
            );

            $profile->update([
                'last_queue_date' => $today,
                'status' => 'standby' // Update status jadi standby
            ]);

            $msg = 'Berhasil masuk antrian';

        } else {
            // --- LOGIKA LEAVE (KELUAR) ---
            
            DB::transaction(function () use ($user, $validated, $request) {
                
                // 1. Hapus dari Antrian
                DriverQueue::where('user_id', $user->id)->delete();

                // 2. Jika alasan "Dapat Penumpang Sendiri"
                if ($request->reason === 'self') {
                    
                    // A. Buat Booking (Simpan ke variabel $booking agar bisa ambil ID-nya)
                    $booking = Booking::create([
                        'cso_id'             => $user->id, // Driver dianggap sebagai pembuat order
                        'driver_id'          => $user->id,
                        'zone_id'            => null, // Tidak pakai zona sistem
                        'manual_destination' => $request->manual_destination,
                        'price'              => $request->manual_price,
                        'status'             => 'Completed', 
                    ]);

                    // B. [BARU] Buat Transaksi (Agar masuk History)
                    // Kita set method 'CashDriver' karena uang diterima driver
                    Transaction::create([
                        'booking_id' => $booking->id,
                        'method'     => 'CashDriver', 
                        'amount'     => $request->manual_price,
                    ]);

                    // C. Potong Saldo 10rb (Fee Aplikasi)
                    Withdrawals::create([
                        'driver_id'    => $user->id,
                        'amount'       => 10000,
                        'status'       => 'Paid', 
                        'type'         => 'fee', // Tandai sebagai potongan
                        'requested_at' => now(),
                        'processed_at' => now(),
                    ]);
                }
            });

            $msg = 'Berhasil keluar antrian';
        }

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

        // 2. PERBAIKAN PENTING DI SINI
        // Reset status profil driver menjadi 'offline'
        // Agar UI kembali ke mode awal (tombol "Masuk Antrian" muncul)
        $request->user()->driverProfile()->update(['status' => 'offline']);
        
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

    /**
     * Update Informasi Rekening Bank Driver
     */
    public function updateBankDetails(Request $request)
    {
        $validated = $request->validate([
            'bank_name' => 'required|string|max:50',
            'account_number' => 'required|string|max:50',
        ]);

        $user = $request->user();
        
        // Update atau Create profile jika belum ada
        $user->driverProfile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'bank_name' => $validated['bank_name'],
                'account_number' => $validated['account_number']
            ]
        );

        return response()->json([
            'message' => 'Informasi rekening berhasil disimpan.',
            'data' => $user->load('driverProfile')
        ]);
    }

    public function updateLocation(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        $user = $request->user();
        $profile = $user->driverProfile;

        // 1. Cek Apakah Driver Ada di Antrian?
        // Jika tidak ada di antrian (karena sudah keluar manual), abaikan GPS update status
        $isInQueue = DriverQueue::where('user_id', $user->id)->exists();
        
        if (!$isInQueue) {
            // Kembalikan status apa adanya (Offline), jangan diubah jadi Standby
            return response()->json(['status' => $profile->status]);
        }

        // 2. Cek Jarak (Logika Lama)
        $airportLat = -0.419266; 
        $airportLng = 117.255554;
        $distance = $this->calculateDistance($airportLat, $airportLng, $request->latitude, $request->longitude);
        
        // Gunakan radius yang sama dengan saat join
        $radius = 10000.0; // 10000.0 untuk testing

        $inArea = ($distance <= $radius);
        
        // 3. Logika Update Status (Hanya jika ada di Queue)
        if ($inArea && $profile->status === 'offline') {
            
            $profile->update(['status' => 'standby']);
            
            // Update koordinat
            DriverQueue::where('user_id', $user->id)->update([
                'latitude' => $request->latitude,
                'longitude' => $request->longitude
            ]);
            
            return response()->json(['status' => 'standby', 'message' => 'Anda memasuki area bandara.']);

        } elseif (!$inArea && $profile->status === 'standby') {
            
            // Kalau keluar radius fisik, set offline tapi TETAP di queue (hanya hidden dari CSO)
            $profile->update(['status' => 'offline']);
            
            return response()->json(['status' => 'offline', 'message' => 'Anda keluar dari area bandara.']);
        }

        // Update koordinat rutin
        if ($profile->status === 'standby') {
             DriverQueue::where('user_id', $user->id)->update([
                'latitude' => $request->latitude,
                'longitude' => $request->longitude
            ]);
        }

        return response()->json(['status' => $profile->status]); 
    }

    public function startBooking(Request $request, Booking $booking)
    {
        if ($request->user()->id !== $booking->driver_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $booking->update(['status' => 'OnTrip']);

        // Update status profil jadi 'ontrip' (biar UI driver tahu dia sedang sibuk)
        $request->user()->driverProfile()->update(['status' => 'ontrip']);

        // Kembalikan profile terbaru agar UI driver terupdate
        return $this->getProfile($request);
    }
}