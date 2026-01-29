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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\WithdrawalRequestNotification;
use App\Services\WhatsAppService;

class DriverApiController extends Controller
{

    /**
     * Helper untuk menyuntikkan "Status Virtual" ke objek user
     * agar Frontend JS tidak error.
     */
    /**
     * Helper untuk memastikan status sinkron dengan antrian real.
     */
    private function attachVirtualStatus($user)
    {
        // 1. Cek apakah user benar-benar ada di tabel antrian?
        $isInQueue = DriverQueue::where('user_id', $user->id)->exists();

        // 2. LOGIKA PERBAIKAN (Sanity Check):
        // Jika Driver TIDAK ADA di antrian, TAPI status di profil masih 'standby'...
        // Itu berarti error / data nyangkut. Kita harus koreksi jadi 'offline'.
        if (!$isInQueue && $user->driverProfile->status === 'standby') {
            
            // Ubah objek user yang akan dikirim ke frontend
            $user->driverProfile->status = 'offline';
            
            // PERBAIKAN PERMANEN: Update database sekalian agar tidak nyangkut lagi
            $user->driverProfile->update(['status' => 'offline']);
        }
        
        // Catatan: Jika status 'ontrip', biarkan saja (karena memang tidak ada di queue)

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
            ->with(['zoneTo:id,name', 'cso:id,name', 'transaction:id,booking_id,method'])
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
            $airportLat = config('taksi.driver_queue.latitude');
            $airportLng = config('taksi.driver_queue.longitude');
            $distance = $this->calculateDistance($airportLat, $airportLng, $request->latitude, $request->longitude);

            // Ganti 2.0 dengan 10000.0 untuk testing
            if ($distance > config('taksi.driver_queue.radius_km')) { 
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

                    // C. (Update Plan: JANGAN Potong Saldo Sekarang)
                    // Biarkan status Unpaid, nanti dihitung sebagai hutang saat getBalance/withdrawal
                    // Withdrawals::create([...]); <-- DIHAPUS
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
        
        // Ambil Rate Komisi (misal 0.2 untuk 20%)
        // Pastikan di database settings value-nya desimal (0.2) atau sesuaikan pembagiannya
        $rate = (float) Setting::where('key', 'commission_rate')->value('value') ?: 0.2;

        // 1. Hitung Pemasukan (Uang di Sistem) - QRIS & CashCSO yang statusnya 'Unpaid'
        $pendingIncome = Transaction::whereHas('booking', function ($q) use ($driver) {
                $q->where('driver_id', $driver->id)
                  ->where('status', 'Completed');
            })
            ->whereIn('method', ['QRIS', 'CashCSO'])
            ->where('payout_status', 'Unpaid')
            ->sum('amount');

        // Hak Driver = Total - Komisi
        $driverShare = $pendingIncome * (1 - $rate);

        // 2. Hitung Hutang (Uang di Driver) - CashDriver yang statusnya 'Unpaid'
        
        // A. Booking via Sistem (Ada Zone ID) -> Kena Rate Komisi
        $standardDebt = Transaction::whereHas('booking', function ($q) use ($driver) {
                $q->where('driver_id', $driver->id)
                  ->where('status', 'Completed')
                  ->whereNotNull('zone_id'); // Syarat: Ada Zona
            })
            ->where('method', 'CashDriver')
            ->where('payout_status', 'Unpaid')
            ->sum('amount');
        
        $debtFromStandard = $standardDebt * $rate;

        // B. Booking Manual (Penumpang Sendiri / Zone ID Null) -> Flat Fee 10.000
        $manualCount = Transaction::whereHas('booking', function ($q) use ($driver) {
                $q->where('driver_id', $driver->id)
                  ->where('status', 'Completed')
                  ->whereNull('zone_id'); // Syarat: Tidak Ada Zona (Manual)
            })
            ->where('method', 'CashDriver')
            ->where('payout_status', 'Unpaid')
            ->count();
        
        $debtFromManual = $manualCount * 10000;

        // Total Hutang Driver ke Sistem
        $driverDebt = $debtFromStandard + $debtFromManual;

        // 3. Saldo Bersih
        $netBalance = $driverShare - $driverDebt;

        return response()->json([
            'balance' => round($netBalance),
            'income_pending' => $driverShare, // Info tambahan buat UI (opsional)
            'debt_pending' => $driverDebt // Info tambahan buat UI (opsional)
        ]);
    }
    
    /**
     * Mengajukan penarikan dana.
     */
    public function requestWithdrawal(Request $request)
    {
        $driver = $request->user();
        
        // --- VALIDASI : Cek Rekening ---
        if (!$driver->driverProfile || empty($driver->driverProfile->account_number)) {
            return response()->json([
                'message' => 'Anda belum mengatur rekening pencairan. Silakan isi nomor rekening Bank BTN di menu Profil.'
            ], 422); // 422 Unprocessable Entity
        }
        

        // 1. Cek Saldo lagi untuk memastikan
        $balanceData = $this->getBalance($request)->getData();
        $amountToWithdraw = $balanceData->balance;

        if ($amountToWithdraw < 10000) {
            return response()->json(['message' => 'Saldo bersih belum mencapai batas minimal pencairan (Rp 10.000).'], 422);
        }

        // Variabel untuk menampung objek withdrawal agar bisa dikirim ke email
        $newWithdrawal = null;

        // 2. Mulai Transaksi Database
        DB::transaction(function () use ($driver, $amountToWithdraw, &$newWithdrawal) {
            
            // A. Buat Record Penarikan
            $newWithdrawal = $driver->withdrawals()->create([
                'amount' => $amountToWithdraw,
                'status' => 'Pending',
                'requested_at' => now(),
            ]);

            // B. KUNCI TRANSAKSI (Ubah status 'Unpaid' -> 'Processing')
            
            // Update transaksi Pemasukan (QRIS/CashCSO)
            Transaction::whereHas('booking', function ($q) use ($driver) {
                $q->where('driver_id', $driver->id);
            })
            ->whereIn('method', ['QRIS', 'CashCSO'])
            ->where('payout_status', 'Unpaid')
            ->update(['payout_status' => 'Processing', 'withdrawal_id' => $newWithdrawal->id]); // Tandai sedang diproses

            // Update transaksi Hutang (CashDriver) - Dianggap lunas/dipotong saat pencairan ini sukses
            Transaction::whereHas('booking', function ($q) use ($driver) {
                $q->where('driver_id', $driver->id);
            })
            ->where('method', 'CashDriver')
            ->where('payout_status', 'Unpaid')
            ->update(['payout_status' => 'Processing', 'withdrawal_id' => $newWithdrawal->id]); // Tandai sedang diproses
        });
        
        // --- KIRIM EMAIL SETELAH TRANSAKSI SUKSES ---
        if ($newWithdrawal) {
            try {
                // Ambil email admin dari Setting (atau hardcode jika belum ada settingnya)
                // Pastikan Anda sudah punya setting key 'admin_email' di database
                $adminEmail = Setting::where('key', 'admin_email')->value('value'); 
                
                // Jika tidak ada di setting, bisa fallback ke email manual (opsional)
                $adminEmail = $adminEmail ?: 'admin@koperasiangkasa.com'; 

                if ($adminEmail) {
                    Mail::to($adminEmail)->send(new WithdrawalRequestNotification($newWithdrawal, $driver));
                }
            } catch (\Exception $e) {
                // Jangan gagalkan request driver hanya karena email gagal terkirim
                // Cukup catat di log
                \Illuminate\Support\Facades\Log::error('Gagal kirim email notifikasi withdrawal: ' . $e->getMessage());
            }
        }

        // --- NOTIFIKASI WHATSAPP ---
        try {
            $waToken = Setting::where('key', 'wa_token')->value('value');
            $adminWa = Setting::where('key', 'admin_wa_number')->value('value');

            if ($waToken && $adminWa && $newWithdrawal) {
                
                $driverName = $driver->name;
                $amountRp = number_format($amountToWithdraw, 0, ',', '.');
                $time = now()->format('d M Y H:i');
                $bank = $driver->driverProfile->bank_name ?? '-';
                $rek = $driver->driverProfile->account_number ?? '-';

                $message = "*PENCAIRAN DANA BARU*\n\n"
                    . "Halo Admin, ada pengajuan baru:\n"
                    . "👤 *Driver:* $driverName\n"
                    . "💰 *Jumlah:* Rp $amountRp\n"
                    . "🏦 *Bank:* $bank ($rek)\n"
                    . "🕒 *Waktu:* $time\n\n"
                    . "Mohon segera cek dashboard admin untuk memproses.";

                // Panggil Service
                WhatsAppService::send($adminWa, $message, $waToken);
            }
        } catch (\Exception $e) {
            // Error WA jangan sampai menggagalkan request driver
            \Illuminate\Support\Facades\Log::error("Gagal kirim WA: " . $e->getMessage());
        }
        // ---------------------------

        return response()->json(['message' => 'Permintaan pencairan berhasil dikirim.'], 201);
    }
    
    /**
     * Mengambil riwayat penarikan dana.
     */
    public function getWithdrawalHistory(Request $request)
    {
        $withdrawals = $request->user()->withdrawals()
            ->orderBy('requested_at', 'desc')
            ->get()
            ->map(function ($withdrawal) {
                 if ($withdrawal->proof_image) {
                     $withdrawal->proof_image_url = asset('storage/' . $withdrawal->proof_image);
                 } else {
                     $withdrawal->proof_image_url = null;
                 }
                 return $withdrawal;
            });
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
            'account_number' => 'required|string|max:50',
        ]);

        $user = $request->user();
        
        // Update atau Create profile jika belum ada
        $user->driverProfile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'bank_name' => 'Bank BTN',
                'account_number' => $validated['account_number']
            ]
        );

        return response()->json([
            'message' => 'Informasi rekening BTN berhasil disimpan.',
            'data' => $user->load('driverProfile')
        ]);
    }

    public function updateLocation(Request $request)
    {
        // ... (Validasi & Cek Queue tetap sama) ...
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        $user = $request->user();
        $profile = $user->driverProfile;

        // 1. Cek Antrian
        $isInQueue = DriverQueue::where('user_id', $user->id)->exists();
        
        // 2. Cek Jarak
        $airportLat = config('taksi.driver_queue.latitude');
        $airportLng = config('taksi.driver_queue.longitude');
        $distance = $this->calculateDistance($airportLat, $airportLng, $request->latitude, $request->longitude);
        $radius = config('taksi.driver_queue.radius_km');
        $inArea = ($distance <= $radius);
        
        // 3. Logika Auto-Join & Grace Period
        // Global Warning: Jika diluar area saat standby -> Cek Grace Period
        $remainingTime = null;
        
        if ($profile->status === 'standby' && !$inArea) {
             // Jika baru saja keluar area (out_of_area_since masih null)
             if (!$profile->out_of_area_since) {
                 $profile->update(['out_of_area_since' => now()]);
                 $remainingTime = 3600; // Full 60 minutes
             } else {
                 // Cek durasi
                 $outSince = \Carbon\Carbon::parse($profile->out_of_area_since);
                 
                 // Gunakan timestamp agar hasil pasti integer dan positif
                 $diffInSeconds = now()->timestamp - $outSince->timestamp;
                 $gracePeriodSeconds = 3600; 
                 
                 if ($diffInSeconds >= $gracePeriodSeconds) {
                     // AUTO KICK
                     DriverQueue::where('user_id', $user->id)->delete();
                     $profile->update([
                         'status' => 'offline',
                         'out_of_area_since' => null
                     ]);
                     
                     return response()->json([
                         'status' => 'offline',
                         'in_area' => false,
                         'message' => 'Antrian hangus karena diluar area lebih dari 60 menit.'
                     ]);
                 }
                 
                 $remainingTime = (int) ($gracePeriodSeconds - $diffInSeconds);
             }
        } elseif ($profile->status === 'standby' && $inArea) {
             // Jika kembali ke area -> Reset Grace Period
             if ($profile->out_of_area_since) {
                 $profile->update(['out_of_area_since' => null]);
             }
        }

        // Logic Auto-Join (Jika Offline & Masuk Area) - Tetap sama
        if ($inArea && $profile->status === 'offline') {
            
            if (!$isInQueue) {
                // Skenario Normal: Masuk Antrian Baru
                $maxSort = DriverQueue::max('sort_order') ?? 0;
                DriverQueue::create([
                    'user_id' => $user->id,
                    'sort_order' => $maxSort + 1,
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude
                ]);
                
                 // Update Status Profil
                $profile->update([
                    'status' => 'standby',
                    'last_queue_date' => now()->toDateString(),
                    'out_of_area_since' => null // Pastikan bersih
                ]);

                return response()->json([
                    'status' => 'standby', 
                    'message' => 'Anda memasuki area bandara (Auto-Queue).',
                    'in_area' => true
                ]);
            } else {
                // Skenario Repair
                 $profile->update([
                    'status' => 'standby',
                    'out_of_area_since' => null
                ]);
                
                return response()->json([
                    'status' => 'standby', 
                    'message' => 'Status antrian dipulihkan (Auto-Repair).',
                    'in_area' => true
                ]);
            }

        }

        // 4. Update Koordinat (Jika sudah di antrian)
        if ($isInQueue) {
             DriverQueue::where('user_id', $user->id)->update([
                'latitude' => $request->latitude,
                'longitude' => $request->longitude
            ]);
        }
        
        return response()->json([
            'status' => $profile->status,
            'in_area' => $inArea,
            'remaining_time' => $remainingTime
        ]); 
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

    /**
     * Fitur Ganti Password Driver
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|string|min:6|confirmed', // Butuh field new_password_confirmation
        ]);

        $user = $request->user();

        // 1. Cek Password Lama
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Password saat ini salah.'], 422);
        }

        // 2. Update Password Baru
        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        return response()->json(['message' => 'Password berhasil diubah.']);
    }

    /**
     * Update Profil Driver (Biodata & Kendaraan)
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        // 1. Validasi Input
        $validated = $request->validate([
            // Validasi Tabel Users
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|max:255|unique:users,email,' . $user->id,
            'phone_number' => 'required|string|max:20|unique:users,phone_number,' . $user->id,
            'username'     => 'required|string|max:50|unique:users,username,' . $user->id,
            
            // Validasi Tabel DriverProfiles
            'car_model'    => 'required|string|max:50',
            'plate_number' => 'required|string|max:20',
        ]);

        DB::transaction(function () use ($user, $validated) {
            // 2. Update Tabel Users
            $user->update([
                'name'         => $validated['name'],
                'email'        => $validated['email'],
                'phone_number' => $validated['phone_number'],
                'username'     => $validated['username'],
            ]);

            // 3. Update Tabel DriverProfiles
            $user->driverProfile()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'car_model'    => $validated['car_model'],
                    'plate_number' => $validated['plate_number']
                ]
            );
        });

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'data'    => $user->load('driverProfile')
        ]);
    }

    public function updateFcmToken(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        $request->user()->update([
            'fcm_token' => $request->fcm_token
        ]);

        return response()->json(['message' => 'FCM Token updated successfully']);
    }
}