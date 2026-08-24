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
        

        // Hitung Posisi Antrian (Jika standby)
        if ($driver->driverProfile && $driver->driverProfile->status === 'standby') {
            $myQueue = DriverQueue::where('user_id', $driver->id)->first();
            if ($myQueue) {
                // Posisi = jumlah antrian yang berada DI DEPAN saya + 1.
                // Pemecah seri created_at wajib ada: seluruh supir rejoin
                // berbagi sort_order 1000, jadi tanpa ini mereka semua melihat
                // nomor yang sama — dan berbeda dari urutan yang benar-benar
                // dipakai CSO (lihat CsoApiController::readyQueueQuery:
                // sort_order ASC, lalu created_at ASC).
                $position = DriverQueue::where(function ($q) use ($myQueue) {
                        $q->where('sort_order', '<', $myQueue->sort_order)
                          ->orWhere(function ($sama) use ($myQueue) {
                              $sama->where('sort_order', $myQueue->sort_order)
                                   ->where('created_at', '<', $myQueue->created_at);
                          });
                    })->count() + 1;
                $driver->queue_position = $position;
            } else {
                $driver->queue_position = null;
            }
        } else {
            $driver->queue_position = null;
        }

        $driver->active_booking = $ongoingBooking;

        // Info jam operasi → app pakai untuk menyalakan ulang layanan lokasi
        // saat jendela kerja dibuka (dan menampilkan status "di luar jam").
        $oh = Setting::operatingHours();
        $driver->operating_hours = [
            'start' => $oh['start'],
            'end'   => $oh['end'],
            'open'  => Setting::isWithinOperatingHours(),
        ];

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
            'latitude'  => 'required_if:action,join|numeric|between:-90,90',
            'longitude' => 'required_if:action,join|numeric|between:-180,180',
            'reason'             => 'required_if:action,leave|in:self,other',
            'manual_destination' => 'required_if:reason,self|string|nullable',
            'manual_price'       => 'required_if:reason,self|numeric|min:0',
        ]);

        $user = $request->user();

        $profile = $user->driverProfile;

        if ($validated['action'] === 'join') {
            // ... (Logika Join Tetap Sama) ...
            $airportLat = Setting::airportLatitude();
            $airportLng = Setting::airportLongitude();
            $distance = $this->calculateDistance($airportLat, $airportLng, $request->latitude, $request->longitude);

            // Radius dari Pengaturan admin (bukan config mentah) supaya jalur
            // join memakai geofence yang sama dengan pengecekan in_area.
            if ($distance > Setting::airportRadiusKm()) {
                return response()->json(['message' => 'Terlalu jauh dari bandara.'], 422);
            }

            // RESET BLOCKED STATUS — sengaja SETELAH pemeriksaan jarak. Kalau
            // dibersihkan lebih dulu, permintaan join yang ditolak pun tetap
            // mematikan blokir, sehingga supir yang tadi dikeluarkan otomatis
            // bisa tertarik masuk lagi oleh auto-join tanpa tindakan sadar.
            $profile->update(['auto_join_blocked' => false]);

            $today = now()->toDateString();
            $sortOrder = $this->queueSortOrderFor($profile);

            // Simpan ke antrian memakai sort_order hasil ROTASI:
            //  - first-join hari ini  -> posisi giliran (mis. 0,1,2,... berdasar line_number)
            //  - re-join              -> 1000 (antrian belakang / grup "Rejoin")
            // Sebelumnya keliru memakai ($maxOrder + 1) sehingga rotasi tidak pernah berlaku
            // dan semua driver selalu masuk ke belakang antrian.
            DriverQueue::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'sort_order' => $sortOrder,
                    // Catatan: TIDAK menyimpan 'joined_at' — kolomnya tidak ada di tabel &
                    // bukan $fillable, jadi selama ini di-drop diam-diam. Waktu masuk antrian
                    // sudah otomatis tercatat di created_at (baris antrian dibuat ulang tiap
                    // join karena 'leave' menghapusnya); admin pun membaca created_at.
                ]
            );

            $profile->update([
                'last_queue_date' => $today,
                'status' => 'standby', // Update status jadi standby
                // Koordinat join ADALAH bukti kehadiran terbaru. Wajib dicatat:
                // penyapu `queue:sweep-stale` menilai kehadiran dari
                // location_updated_at, jadi tanpa ini supir yang baru saja
                // menekan "Masuk Antrian" bisa langsung tersapu keluar.
                'latitude'            => $request->latitude,
                'longitude'           => $request->longitude,
                'location_updated_at' => now(),
            ]);
            
            // LOG ACTIVITY
            $this->logActivity($user->id, 'QUEUE_JOIN', 'Masuk Antrian');

            $msg = 'Berhasil masuk antrian';

        } else {
            // --- LOGIKA LEAVE (KELUAR) ---
            
            DB::transaction(function () use ($user, $validated, $request) {
                
                // 1. Hapus dari Antrian
                DriverQueue::where('user_id', $user->id)->delete();

                // 2. Jika alasan "Dapat Penumpang Sendiri"
                if ($request->reason === 'self') {
                    
                    // A. Buat Booking Start OnTrip
                    Booking::create([
                        'cso_id'             => $user->id, 
                        'driver_id'          => $user->id,
                        'zone_id'            => null,
                        'manual_destination' => $request->manual_destination,
                        'price'              => $request->manual_price,
                        'status'             => 'OnTrip',
                    ]);

                    // B. Update Status Profil jadi 'ontrip'
                    $user->driverProfile()->update(['status' => 'ontrip']);

                    // LOG ACTIVITY
                    $this->logActivity($user->id, 'TRIP_START_SELF', 'Mulai Trip Mandiri: ' . $request->manual_destination);

                } else {
                    // Keluar Biasa / Off
                    $user->driverProfile()->update(['status' => 'offline']);
                    
                    // LOG ACTIVITY
                    $this->logActivity($user->id, 'QUEUE_LEAVE', 'Keluar Antrian (Istirahat/Lainnya)');
                }
            });

            $msg = 'Berhasil keluar antrian';
            if($request->reason === 'self') $msg .= ' (Self Passenger)';
        }



        $user->load('driverProfile');
        $userWithStatus = $this->attachVirtualStatus($user);

        return response()->json([
            'message' => $msg,
            'data' => $userWithStatus
        ]);
    }

    /**
     * Posisi antrian untuk supir yang BARU masuk — dipakai jalur join manual
     * MAUPUN auto-join, supaya kedua pintu masuk tidak pernah lagi memakai
     * aturan berbeda (dulu auto-join memakai max+1 sehingga rotasi harian
     * tidak pernah berlaku, bahkan bisa menaruh first-join di belakang grup
     * rejoin yang ber-sort_order 1000).
     *
     *  - kedatangan PERTAMA hari ini -> urutan giliran hasil rotasi
     *    (line_number vs daily_start_line), bernilai 0..jumlah_supir
     *  - kedatangan berikutnya       -> 1000 (grup "Rejoin", antrian belakang)
     */
    private function queueSortOrderFor($profile): int
    {
        $isFirstJoinToday = ($profile->last_queue_date !== now()->toDateString());

        if (!$isFirstJoinToday || !$profile->line_number) {
            return 1000;
        }

        // Ambil Angka Giliran Hari Ini (Default 1 jika error)
        $dailyStart = (int) Setting::getValue('daily_start_line') ?: 1;
        $myLine = (int) $profile->line_number;
        $totalDrivers = \App\Models\DriverProfile::max('line_number') ?: 30; // jumlah driver dinamis

        // Rumus Matematika Rotasi
        if ($myLine >= $dailyStart) {
            // Kasus Normal: Giliran 5, Saya 6. Posisi = 6 - 5 = 1 (Urutan ke-2 karena index 0)
            return $myLine - $dailyStart;
        }

        // Kasus Wrapping: Giliran 28, Saya 2. Saya harus di bawah 30.
        // Posisi = (30 - 28) + 2 = 4.
        return ($totalDrivers - $dailyStart) + $myLine;
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

        // Hanya order yang sedang berjalan (OnTrip) yang boleh diselesaikan.
        // Mencegah "menyelesaikan" order yang belum dimulai (Assigned),
        // sudah selesai (Completed), atau dibatalkan (Cancelled). Penting:
        // tanpa cek ini, blok self-order di bawah bisa membuat catatan
        // hutang (Transaction) ganda untuk order yang sama.
        if ($booking->status !== 'OnTrip') {
            return response()->json([
                'message' => 'Order ini tidak bisa diselesaikan (status saat ini: ' . $booking->status . ').',
            ], 422);
        }

        // Update status booking saja.
        // Tidak perlu update status driver_profile (karena kolomnya sudah dihapus).
        // Driver otomatis jadi 'offline' (tidak di queue) setelah trip selesai.

        // --- NEW LOGIC FOR SELF PASSENGER ---
        // Jika ini adalah Self Order (CSO = Driver, Zone = Null)
        if ($booking->cso_id == $request->user()->id && is_null($booking->zone_id)) {
            // Catat sebagai Hutang (Unpaid Transaction)
            Transaction::create([
                'booking_id'    => $booking->id,
                'method'        => 'CashDriver', // Uang di driver
                'amount'        => $booking->price, // Nominal tarif yang diinput
                'payout_status' => 'Unpaid', // Belum disetor
            ]);
        }
        
        $booking->update(['status' => 'Completed']);

        // 2. PERBAIKAN PENTING DI SINI
        // Reset status profil driver menjadi 'offline'
        // Agar UI kembali ke mode awal (tombol "Masuk Antrian" muncul)
        $request->user()->driverProfile()->update(['status' => 'offline']);
        
        // LOG ACTIVITY
        $this->logActivity($request->user()->id, 'TRIP_FINISH', 'Selesai Trip No. ' . $booking->id);

        // Panggil getProfile untuk mengembalikan data terbaru ke frontend
        return $this->getProfile($request);
        
    }

    /**
     * Mengambil saldo dompet driver.
     */
    public function getBalance(Request $request)
    {
        $driver = $request->user();

        // Rate Komisi (mis. 0.2 = 20%). Disimpan desimal di settings.
        $rate = (float) Setting::getValue('commission_rate') ?: 0.2;
        $manualFlat = (int) (Setting::getValue('manual_fee_flat') ?: 10000);

        // === 1. PEMASUKAN (uang ada di sistem → hak driver) ===
        //     QRIS & CashCSO yang masih 'Unpaid'.
        $incomeQ = Transaction::whereHas('booking', function ($q) use ($driver) {
                $q->where('driver_id', $driver->id)->where('status', 'Completed');
            })
            ->whereIn('method', ['QRIS', 'CashCSO'])
            ->where('payout_status', 'Unpaid');
        $incomeGross = (float) (clone $incomeQ)->sum('amount');
        $incomeCount = (clone $incomeQ)->count();
        $commission  = $incomeGross * $rate;          // potongan komisi koperasi
        $driverShare = $incomeGross - $commission;    // hak bersih driver

        // === 2. HUTANG (uang dipegang driver → setoran ke koperasi) ===
        // A. Booking via sistem (ada zona) → kena komisi rate.
        $stdQ = Transaction::whereHas('booking', function ($q) use ($driver) {
                $q->where('driver_id', $driver->id)
                  ->where('status', 'Completed')
                  ->whereNotNull('zone_id');
            })
            ->where('method', 'CashDriver')
            ->where('payout_status', 'Unpaid');
        $standardGross    = (float) (clone $stdQ)->sum('amount');
        $standardCount    = (clone $stdQ)->count();
        $debtFromStandard = $standardGross * $rate;

        // B. Booking manual (tanpa zona) → fee flat per trip.
        $manualCount = Transaction::whereHas('booking', function ($q) use ($driver) {
                $q->where('driver_id', $driver->id)
                  ->where('status', 'Completed')
                  ->whereNull('zone_id');
            })
            ->where('method', 'CashDriver')
            ->where('payout_status', 'Unpaid')
            ->count();
        $debtFromManual = $manualCount * $manualFlat;

        $driverDebt = $debtFromStandard + $debtFromManual;

        // === 3. SALDO BERSIH ===
        $netBalance = $driverShare - $driverDebt;

        return response()->json([
            // Kunci lama (kompatibilitas mundur)
            'balance'        => round($netBalance),
            'income_pending' => round($driverShare),
            'debt_pending'   => round($driverDebt),
            // Rincian lengkap untuk UI dompet yang transparan
            'breakdown' => [
                'commission_rate' => $rate,
                'manual_fee_flat' => $manualFlat,
                'income' => [
                    'gross'      => round($incomeGross),
                    'commission' => round($commission),
                    'net'        => round($driverShare),
                    'count'      => $incomeCount,
                ],
                'debt' => [
                    'standard_gross' => round($standardGross),
                    'standard_fee'   => round($debtFromStandard),
                    'standard_count' => $standardCount,
                    'manual_count'   => $manualCount,
                    'manual_fee'     => round($debtFromManual),
                    'total'          => round($driverDebt),
                ],
                'net' => round($netBalance),
            ],
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
                $adminEmail = Setting::getValue('admin_email'); 
                
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
            $waToken = Setting::getValue('wa_token');
            $adminWa = Setting::getValue('admin_wa_number');

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
                \App\Jobs\SendWhatsAppMessage::dispatch($adminWa, $message, $waToken);
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
        
        // Batasi payload mobile: 200 transaksi terbaru. Data lebih lama tetap bisa
        // diambil lewat filter date_from/date_to di atas. (Tetap array datar →
        // tidak memutus parsing di app.)
        $history = $query->orderBy('created_at', 'desc')->limit(200)->get();
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

    /**
     * Titik pusat & radius area bandara untuk peta di beranda supir.
     *
     * Dipisah dari updateLocation() yang punya belasan titik keluar berbeda —
     * peta hanya butuh geofence-nya, dan datanya nyaris tak pernah berubah
     * sehingga aman diambil sekali lalu di-cache aplikasi.
     * Bentuk `base` sengaja disamakan dengan endpoint peta CSO & admin.
     */
    public function getAirportArea()
    {
        return response()->json([
            'base' => [
                'latitude'  => Setting::airportLatitude(),
                'longitude' => Setting::airportLongitude(),
                'radius_km' => Setting::airportRadiusKm(),
            ],
        ]);
    }

    public function updateLocation(Request $request)
    {
        // ... (Validasi & Cek Queue tetap sama) ...
        $request->validate([
            // Rentang WAJIB dibatasi: tanpa ini, fix cacat (0,0) diterima apa
            // adanya dan menghasilkan jarak ~12.000 km — supir langsung
            // dinyatakan di luar area dan jam tenggangnya mulai berjalan.
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            // Akurasi (meter) dari perangkat. Opsional supaya versi aplikasi
            // lama tetap jalan — bila tidak dikirim, fix dianggap layak.
            'accuracy'  => 'nullable|numeric|min:0',
        ]);

        // (0,0) bukan lokasi nyata — itu sentinel "belum ada fix" yang memang
        // muncul di sistem ini (lihat catatan pre-fill rotasi di
        // CsoApiController::readyQueueQuery). Kalau diterima, jaraknya ke
        // bandara ~12.000 km dan supir langsung dinyatakan di luar area.
        // Ditolak SEBELUM disimpan supaya tidak ikut menyegarkan bukti
        // kehadiran (location_updated_at) juga.
        if ((float) $request->latitude === 0.0 && (float) $request->longitude === 0.0) {
            return response()->json(['message' => 'Koordinat tidak valid (0,0).'], 422);
        }

        $user = $request->user();
        $profile = $user->driverProfile;

        // Selalu simpan lokasi terakhir di profil — termasuk saat driver sedang
        // OnTrip (tidak ada di tabel antrian). Inilah yang membuat peta CSO bisa
        // tetap menampilkan & melacak pergerakan driver yang sedang mengantar.
        if ($profile) {
            $profile->update([
                'latitude'            => $request->latitude,
                'longitude'           => $request->longitude,
                'location_updated_at' => now(),
            ]);
        }

        // Rekam jejak pergerakan (breadcrumb) untuk gambaran RUTE di panel admin.
        // Throttle per jarak (~20 m) agar trail rapi & hemat storage.
        if ($profile) {
            $movedFar = $profile->track_lat === null
                || $this->calculateDistance(
                        (float) $profile->track_lat, (float) $profile->track_lng,
                        $request->latitude, $request->longitude
                   ) >= 0.02;
            if ($movedFar) {
                \App\Models\DriverLocationLog::create([
                    'user_id'     => $user->id,
                    'latitude'    => $request->latitude,
                    'longitude'   => $request->longitude,
                    'recorded_at' => now(),
                ]);
                $profile->update([
                    'track_lat' => $request->latitude,
                    'track_lng' => $request->longitude,
                ]);
            }
        }

        // 1. Cek Antrian
        $isInQueue = DriverQueue::where('user_id', $user->id)->exists();
        
        // 2. Cek Jarak
        $airportLat = Setting::airportLatitude();
        $airportLng = Setting::airportLongitude();
        $distance = $this->calculateDistance($airportLat, $airportLng, $request->latitude, $request->longitude);
        $radius = Setting::airportRadiusKm();
        $prevInArea = $profile ? $profile->last_in_area : null; // null = belum ada baseline

        // Fix yang akurasinya buruk TIDAK dipakai untuk memutuskan geofence:
        // posisinya sudah terlanjur disimpan di atas (peta CSO tetap hidup dan
        // supir tetap terhitung "mengirim kabar"), tapi status di dalam/luar
        // area dipertahankan apa adanya sampai datang fix yang layak.
        $accuracy = $request->filled('accuracy') ? (float) $request->accuracy : null;
        $batasAkurasi = (float) config('taksi.driver_queue.poor_accuracy_meters');
        $fixMeragukan = $accuracy !== null && $batasAkurasi > 0 && $accuracy > $batasAkurasi;

        if ($fixMeragukan && $prevInArea !== null) {
            $inArea = (bool) $prevInArea;
        } else {
            // Histeresis: sekali di DALAM, baru dianggap keluar setelah melewati
            // radius + sabuk. Mencegah supir yang parkir di tepi lingkaran
            // bolak-balik "masuk-keluar" hanya karena jitter GPS.
            $sabuk = (float) config('taksi.driver_queue.geofence_hysteresis_km');
            $ambang = $prevInArea === true ? $radius + $sabuk : $radius;
            $inArea = ($distance <= $ambang);
        }

        // Tenggang "di luar area" (detik) dari Pengaturan admin. 0 = auto-keluar
        // antrian dinonaktifkan: supir tetap ditandai di luar area & tercatat di
        // log, tapi antriannya tidak pernah hangus sendiri.
        $graceMinutes = Setting::outOfAreaGraceMinutes();
        $graceSeconds = $graceMinutes * 60;

        // Gerbang jam operasi: app akan mematikan layanan lokasi bila false
        // & tidak sedang mengantar (lihat background_service.dart).
        $trackingOpen = Setting::isWithinOperatingHours();

        // Hitung berapa kali supir KELUAR-MASUK area bandara (independen dari
        // status antrian) — bandingkan dengan state in_area sebelumnya.
        if ($profile) {
            $prev = $prevInArea; // null (baseline) | bool — dibaca sebelum histeresis
            if ($prev === null) {
                $profile->update(['last_in_area' => $inArea]);
            } elseif ((bool) $prev !== $inArea) {
                if ($inArea) {
                    $profile->increment('airport_entries');
                    $this->logActivity($user->id, 'AIRPORT_ENTER', 'Masuk area bandara');
                } else {
                    $profile->increment('airport_exits');
                    $this->logActivity($user->id, 'AIRPORT_EXIT', 'Keluar area bandara');
                }
                $profile->update(['last_in_area' => $inArea]);
            }
        }

        // 3. Logika Auto-Join & Grace Period
        // Global Warning: Jika diluar area saat standby -> Cek Grace Period
        $remainingTime = null;
        
        if ($profile->status === 'standby' && !$inArea && $graceSeconds === 0) {
             // Auto-keluar dimatikan admin. Penanda TETAP diisi — CSO memakai
             // `out_of_area_since` untuk menyaring supir yang tak boleh dapat
             // order (lihat CsoApiController::readyQueueQuery) — tapi selalu
             // disegarkan ke SEKARANG supaya jam tenggang tidak diam-diam
             // menumpuk. Kalau admin menyalakan lagi auto-keluar, hitung mundur
             // mulai dari nol, bukan dari saat supir keluar area berjam-jam lalu.
             if (!$profile->out_of_area_since) {
                 $this->logActivity($user->id, 'AREA_LEAVE_WARNING', 'Keluar Area (auto-keluar nonaktif)');
             }
             $profile->update(['out_of_area_since' => now()]);
        } elseif ($profile->status === 'standby' && !$inArea) {
             // Jika baru saja keluar area (out_of_area_since masih null)
             if (!$profile->out_of_area_since) {
                 $profile->update(['out_of_area_since' => now()]);
                 // LOG ACTIVITY
                 $this->logActivity($user->id, 'AREA_LEAVE_WARNING', 'Keluar Area (Peringatan dimulai)');
                 $remainingTime = $graceSeconds > 0 ? $graceSeconds : null;
             } elseif ($graceSeconds > 0) {
                 // Cek durasi
                 $outSince = \Carbon\Carbon::parse($profile->out_of_area_since);
                 
                 // Gunakan timestamp agar hasil pasti integer dan positif
                 $diffInSeconds = now()->timestamp - $outSince->timestamp;
                 
                 if ($diffInSeconds >= $graceSeconds) {
                     // AUTO KICK
                     DriverQueue::where('user_id', $user->id)->delete();
                     $profile->update([
                         'status' => 'offline',
                         'out_of_area_since' => null,
                         'auto_join_blocked' => true // BLOCK AUTO JOIN
                     ]);

                     // LOG ACTIVITY
                     $this->logActivity($user->id, 'QUEUE_LEAVE_AUTO', "Dikeluarkan dari antrian (Timeout: >{$graceMinutes} menit diluar area)");
                     
                     return response()->json([
                         'status' => 'offline',
                         'in_area' => false,
                         'tracking_open' => $trackingOpen,
                         'grace_enabled' => true,
                         'message' => "Antrian hangus karena diluar area lebih dari {$graceMinutes} menit."
                     ]);
                 }
                 
                 $remainingTime = (int) ($graceSeconds - $diffInSeconds);
             }
        } elseif ($profile->status === 'standby' && $inArea) {
             // Jika kembali ke area -> Reset Grace Period
             if ($profile->out_of_area_since) {
                 $profile->update(['out_of_area_since' => null]);
                 // LOG ACTIVITY
                 $this->logActivity($user->id, 'AREA_RETURN', 'Kembali ke Area (Peringatan dihapus)');
             }
        }

        // Logic Auto-Join (Jika Offline & Masuk Area) - Tetap sama
        if ($inArea && $profile->status === 'offline') {
            
            // CEK APAKAH DIBLOKIR?
            if ($profile->auto_join_blocked) {
                return response()->json([
                    'status' => 'offline',
                    'in_area' => true,
                    'tracking_open' => $trackingOpen,
                    'message' => 'Anda harus menekan tombol "Masuk Antrian" secara manual.'
                ]);
            }

            if (!$isInQueue) {
                // Skenario Normal: Masuk Antrian Baru.
                // Urutan memakai rumus rotasi yang SAMA dengan join manual —
                // lihat queueSortOrderFor().
                DriverQueue::create([
                    'user_id' => $user->id,
                    'sort_order' => $this->queueSortOrderFor($profile),
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude
                ]);
                
                // Update Status Profil
                $profile->update([
                    'status' => 'standby',
                    'last_queue_date' => now()->toDateString(),
                    'out_of_area_since' => null // Pastikan bersih
                ]);

                // LOG ACTIVITY
                $this->logActivity($user->id, 'QUEUE_JOIN_AUTO', 'Masuk Antrian Otomatis (Masuk Area)');

                return response()->json([
                    'status' => 'standby',
                    'message' => 'Anda memasuki area bandara (Auto-Queue).',
                    'in_area' => true,
                    'tracking_open' => $trackingOpen
                ]);
            } else {
                // Skenario Repair
                 $profile->update([
                    'status' => 'standby',
                    'out_of_area_since' => null
                ]);
                
                // LOG ACTIVITY
                $this->logActivity($user->id, 'QUEUE_JOIN_REPAIR', 'Masuk Antrian (Repair/Recovery)');

                return response()->json([
                    'status' => 'standby',
                    'message' => 'Status antrian dipulihkan (Auto-Repair).',
                    'in_area' => true,
                    'tracking_open' => $trackingOpen
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
        
        // 5. Tambahkan info line_number agar Notifikasi HP bisa baca
        $lineNumber = $profile->line_number;

        return response()->json([
            'status' => $profile->status,
            'in_area' => $inArea,
            'remaining_time' => $remainingTime,
            // false = admin mematikan auto-keluar antrian; app menampilkan
            // status "aman" alih-alih hitung mundur kosong.
            'grace_enabled' => $graceSeconds > 0,
            'line_number' => $lineNumber,
            'tracking_open' => $trackingOpen,
        ]);
    }

    
    public function startBooking(Request $request, Booking $booking)
    {
        if ($request->user()->id !== $booking->driver_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Hanya order yang belum dimulai & belum selesai yang boleh di-Start.
        // (Sebelumnya tanpa cek status: order Completed/Cancelled pun bisa
        //  dipaksa menjadi OnTrip.)
        if (in_array($booking->status, ['OnTrip', 'Completed', 'Cancelled'])) {
            return response()->json([
                'message' => 'Order ini tidak bisa dimulai (status saat ini: ' . $booking->status . ').',
            ], 422);
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
    private function logActivity($userId, $type, $desc)
    {
        try {
            \App\Models\DriverActivity::create([
                'user_id' => $userId,
                'activity_type' => $type,
                'description' => $desc
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Activiy Log Error: ' . $e->getMessage());
        }
    }
}