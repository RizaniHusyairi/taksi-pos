<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Zone;
use App\Models\User;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\DriverProfile;
use App\Models\DriverQueue; 
use App\Models\Setting; 
use Illuminate\Support\Facades\Hash;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewOrderForDriver; // Kita akan buat Mailable ini nanti
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CsoApiController extends Controller
{

    /**
     * Mengambil data profil CSO yang sedang login
     */
    public function getProfile(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * Update Biodata (Nama & Username)
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username,' . $user->id,
        ]);

        $user->update([
            'name' => $validated['name'],
            'username' => $validated['username'],
        ]);

        return response()->json([
            'message' => 'Profil berhasil diperbarui',
            'data' => $user
        ]);
    }

    /**
     * Ganti Password
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|string|min:6|confirmed', // butuh field new_password_confirmation di frontend
        ]);

        $user = $request->user();

        // Cek password lama
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Password saat ini salah.'], 422);
        }

        // Update password baru
        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        return response()->json(['message' => 'Password berhasil diubah.']);
    }


    /**
     * Mengambil daftar semua zona tujuan.
     */
    public function getZones()
    {
        // Mengambil semua zona, mungkin Anda ingin mengurutkannya
        $zones = Zone::orderBy('name')->get();
        return response()->json($zones);
    }

    /**
     * Mengambil daftar supir yang statusnya 'available'.
     */
    public function getAvailableDrivers()
    {
        // Ambil data dari tabel queue, join ke users & profiles
        // Urutkan berdasarkan sort_order ASC (0, 1, 2 ... 1000)
        // Jika sort_order sama (sesama 1000), urutkan berdasarkan created_at (siapa cepat dia dapat)


       $drivers = DriverQueue::with(['driver.driverProfile'])
            ->whereHas('driver.driverProfile', function ($query) {
                $query->whereNull('out_of_area_since');
            })
            ->orderBy('sort_order', 'asc')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($queue) {
                $user = $queue->driver;
                
                // --- PERBAIKAN DI SINI ---
                // JANGAN menimpa status secara manual lagi!
                // Biarkan status asli dari database (standby/offline) yang lewat.
                
                /* KODE LAMA YANG HARUS DIHAPUS/KOMENTAR:
                if ($user && $user->driverProfile) {
                    $user->driverProfile->status = 'available'; 
                }
                */

                // Kita bisa tambahkan info sort_order untuk debugging jika mau
                if ($user && $user->driverProfile) {
                    $user->driverProfile->queue_score = $queue->sort_order;
                }
                
                return $user;
            })
            ->filter(function ($user) {
                return $user != null;
            })
            ->values();

        return response()->json($drivers);
    }

    /**
     * Lokasi para supir untuk peta dashboard CSO.
     * Sumber posisi: tabel driver_queues (diperbarui saat supir mengirim lokasi).
     * Juga mengembalikan titik bandara + radius untuk pusat & lingkaran peta.
     */
    public function getDriverLocations()
    {
        $drivers = DriverQueue::with(['driver.driverProfile'])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('latitude', '!=', 0)
            ->where('longitude', '!=', 0)
            ->orderBy('sort_order', 'asc')
            ->get()
            ->map(function ($q) {
                $user = $q->driver;
                if (!$user) {
                    return null;
                }
                $profile = $user->driverProfile;

                return [
                    'id'           => $user->id,
                    'name'         => $user->name,
                    'line_number'  => $profile?->line_number,
                    'car_model'    => $profile?->car_model,
                    'plate_number' => $profile?->plate_number,
                    'status'       => $profile?->status ?? 'offline',
                    'queue_score'  => $q->sort_order,
                    'latitude'     => (float) $q->latitude,
                    'longitude'    => (float) $q->longitude,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'base' => [
                'latitude'  => (float) config('taksi.driver_queue.latitude'),
                'longitude' => (float) config('taksi.driver_queue.longitude'),
                'radius_km' => (float) config('taksi.driver_queue.radius_km'),
            ],
            'drivers' => $drivers,
        ]);
    }

    /**
     * Ringkasan untuk dashboard CSO: pendapatan & transaksi hari ini,
     * rincian Cash vs QRIS, jumlah antrian, dan transaksi terakhir.
     */
    public function getDashboardStats(Request $request)
    {
        $cso = $request->user();
        $today = now()->toDateString();

        // Query dasar: transaksi milik CSO ini
        $base = Transaction::whereHas('booking', function ($q) use ($cso) {
            $q->where('cso_id', $cso->id);
        });

        $todayTx = (clone $base)->whereDate('created_at', $today)->get();

        $cashSum = $todayTx->whereIn('method', ['CashCSO', 'CashDriver'])->sum('amount');
        $qrisSum = $todayTx->where('method', 'QRIS')->sum('amount');

        $recent = (clone $base)
            ->with([
                'booking.zoneTo:id,name',
                'booking.driver.driverProfile',
                'booking.cso',
                'booking',
            ])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $queueTotal = DriverQueue::count();
        $queueReady = DriverQueue::whereHas('driver.driverProfile', function ($q) {
            $q->whereIn('status', ['standby', 'available']);
        })->count();

        return response()->json([
            'today' => [
                'count'   => $todayTx->count(),
                'revenue' => (float) $todayTx->sum('amount'),
                'cash'    => (float) $cashSum,
                'qris'    => (float) $qrisSum,
            ],
            'queue' => [
                'total' => $queueTotal,
                'ready' => $queueReady,
            ],
            'recent' => $recent,
        ]);
    }

    /**
     * Menyimpan booking baru.
     */
    public function storeBooking(Request $request)
    {
        $validated = $request->validate([
            'driver_id' => 'required|exists:users,id',
            'zone_id'   => 'required|exists:zones,id',
        ]);



        $zone = Zone::findOrFail($validated['zone_id']);
        $cso = Auth::user();

        // Mulai transaksi database untuk konsistensi
        $booking = DB::transaction(function () use ($cso, $validated, $zone) {
            // 1. Buat booking
            $newBooking = Booking::create([
                'cso_id'    => $cso->id,
                'driver_id' => $validated['driver_id'],
                'zone_id'   => $zone->id,
                'price'     => $zone->price,
                'status'    => 'Assigned',
            ]);

            // 2. HAPUS DARI ANTRIAN (Kick from queue)
            DriverQueue::where('user_id', $validated['driver_id'])->delete();

            // 3. LOG ACTIVITY (Supir)
            $this->logDriverActivity($validated['driver_id'], 'ORDER_RECEIVED', 'Dapat Order dari CSO: ' . $cso->name . ' -> ' . $zone->name);
            
            // 4. LOG ACTIVITY (Keluar Antrian karena Order)
            $this->logDriverActivity($validated['driver_id'], 'QUEUE_LEAVE_ORDER', 'Keluar Antrian (Dapat Order)');

            return $newBooking;
        });

        return response()->json($booking, 201); // 201 Created
    }

    /**
     * Mencatat pembayaran untuk sebuah booking.
     */
    public function recordPayment(Request $request)
    {
        $validated = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'method'     => 'required|in:QRIS,CashCSO,CashDriver',
            // Validasi: payment_proof wajib ada JIKA methodnya QRIS
            'payment_proof' => 'required_if:method,QRIS|image|mimes:jpeg,png,jpg|max:5120', // Max 5MB
        ], [
            'payment_proof.required_if' => 'Mohon upload foto bukti transfer QRIS.',
            'payment_proof.image' => 'File bukti harus berupa gambar.',
        ]);

        $booking = Booking::findOrFail($validated['booking_id']);
        
        DB::transaction(function () use ($booking, $validated, $request) {
            
            $proofPath = null;

            // Proses Upload Gambar jika ada
            if ($request->hasFile('payment_proof')) {
                // Simpan di folder 'public/payment_proofs'
                $proofPath = $request->file('payment_proof')->store('payment_proofs', 'public');
            }

            // 1. Buat record transaksi
            Transaction::create([
                'booking_id'    => $booking->id,
                'method'        => $validated['method'],
                'amount'        => $booking->price,
                'payment_proof' => $proofPath, // Simpan path gambar ke database
            ]);

            // 2. Update status booking (Logika lama tetap jalan)
            // Jika CashDriver statusnya beda, jika QRIS/CashCSO jadi 'Paid' (atau logic existing kamu)
            $newStatus = ($validated['method'] === 'CashDriver') ? 'CashDriver' : 'Paid';
            
            // Khusus QRIS, status 'Paid' sudah valid karena bukti sudah diupload CSO
            $booking->update(['status' => $newStatus]);
        });
        
        return response()->json(['message' => 'Payment recorded successfully'], 201);
    }

    public function processOrder(Request $request)
    {
        

        // 1. Validasi Input Lengkap
        $validated = $request->validate([
            'driver_id'     => 'required|exists:users,id',
            'zone_id'       => 'required|exists:zones,id',
            'method'        => 'required|in:QRIS,CashCSO,CashDriver',
            // Bukti foto wajib jika QRIS
            'payment_proof' => 'required_if:method,QRIS|image|mimes:jpeg,png,jpg|max:5120',
            'passenger_phone' => 'required|string|min:10|max:15',
        ], [
            'payment_proof.required_if' => 'Wajib upload foto bukti transfer untuk QRIS.',
        ]);

        // Cek apakah driver sedang dalam perjalanan (Punya booking belum selesai)
        $hasActiveBooking = Booking::where('driver_id', $validated['driver_id'])
            ->whereIn('status', ['Assigned', 'OnTrip'])
            ->exists();

        if ($hasActiveBooking) {
            return response()->json([
                'message' => 'Supir ini sedang menjalankan orderan lain dan belum selesai.'
            ], 422); // Unprocessable Entity
        }

        $zone = Zone::findOrFail($validated['zone_id']);
        $cso = Auth::user();

        // 2. Mulai Transaksi Database (Atomic)
        $result = DB::transaction(function () use ($validated, $zone, $cso, $request) {
            
            
            $status = 'Assigned';

            // B. Simpan Data Booking
            $booking = Booking::create([
                'cso_id'    => $cso->id,
                'driver_id' => $validated['driver_id'],
                'zone_id'   => $zone->id,
                'price'     => $zone->price,
                'status'    => $status, 
                'passenger_phone' => $validated['passenger_phone']
            ]);

            // C. Simpan Transaksi (Jika ada pembayaran ke kantor/QRIS)
            // Jika CashDriver, biasanya tidak dicatat di tabel transactions sampai supir setor, 
            // TAPI agar struk bisa dicetak lengkap, kita catat saja sebagai record history.
            
            $proofPath = null;
            if ($request->hasFile('payment_proof')) {
                $proofPath = $request->file('payment_proof')->store('payment_proofs', 'public');
            }

            Transaction::create([
                'booking_id'    => $booking->id,
                'method'        => $validated['method'],
                'amount'        => $zone->price,
                'payment_proof' => $proofPath,
            ]);

            // D. Hapus Driver dari Antrian (PENTING)
            DriverQueue::where('user_id', $validated['driver_id'])->delete();

            // Load data lengkap untuk dikembalikan ke frontend (guna cetak struk)
            // 'transaction' diikutkan agar app bisa membangun link/QR struk dari ID transaksi.
            return $booking->load(['driver.driverProfile', 'zoneTo', 'cso', 'transaction']);
        });

        // 3. LOGIKA NOTIFIKASI (Di luar transaction DB agar tidak lambat)
        try {
            $driver = $result->driver;
            $waToken = Setting::where('key', 'wa_token')->value('value');
            
            // Link Struk (menggunakan ID transaksi)
            $receiptUrl = route('receipt.show', $result->transaction->id);
            
            $zoneName = $result->zoneTo->name;
            $priceRp = number_format($result->price, 0, ',', '.');

            // --- AMBIL DATA SUPIR & LINE NUMBER ---
            $driverName = $driver->name;
            // Cek apakah ada line number, jika ada format jadi (#L5), jika tidak kosongkan
            $driverLine = !empty($driver->driverProfile->line_number) 
                ? "(#L" . $driver->driverProfile->line_number . ")" 
                : "";
            
            // --- A. KIRIM WA KE PENUMPANG ---
            if ($waToken && $validated['passenger_phone']) {
                $msgPassenger = "*STRUK PEMBAYARAN TAKSI*\n\n"
                    . "Terima kasih telah menggunakan jasa Koperasi Angkasa Jaya.\n\n"
                    . "📍 Tujuan: $zoneName\n"
                    . "🚖 Supir: *$driverName $driverLine*\n" // <--- BARIS INI DITAMBAHKAN
                    . "💰 Tarif: Rp $priceRp\n\n"
                    . "Lihat struk digital Anda di sini:\n"
                    . "$receiptUrl\n\n"
                    . "Selamat menikmati perjalanan!";
            WhatsAppService::send($validated['passenger_phone'], $msgPassenger, $waToken);
            }

            // --- B. KIRIM WA KE DRIVER ---
            // Asumsi driver punya no HP di kolom 'phone_number' atau 'username'
            $driverPhone = $driver->phone_number ?? $driver->username;
            
            if ($waToken && $driverPhone) {
                $msgDriver = "*ORDER BARU MASUK!* 🚖\n\n"
                    . "Tujuan: *$zoneName*\n"
                    . "Penumpang: " . $validated['passenger_phone'] . "\n"
                    . "Tarif: Rp $priceRp\n\n"
                    . "Struk Pembayaran:\n$receiptUrl\n\n"
                    . "Harap segera menuju titik jemput.";

                WhatsAppService::send($driverPhone, $msgDriver, $waToken);
            }

            // --- C. KIRIM EMAIL KE DRIVER ---
            if ($driver->email) {
                // Pastikan Anda sudah membuat Mail Class: php artisan make:mail NewOrderForDriver
                Mail::to($driver->email)->send(new \App\Mail\NewOrderForDriver($result, $receiptUrl));
            }

            // --- D. NOTIFIKASI FCM KE DRIVER APP (HTTP v1) ---
            if ($driver->fcm_token) {
                try {
                    $credentialsPath = storage_path('app/firebase_credentials.json');
                    
                    if (!file_exists($credentialsPath)) {
                        Log::error("FCM Error: Credentials file not found at $credentialsPath");
                    } else {
                        // 1. Get Access Token
                        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
                        $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials(
                            $scopes,
                            $credentialsPath
                        );
                        $token = $credentials->fetchAuthToken(\Google\Auth\HttpHandler\HttpHandlerFactory::build());
                        $accessToken = $token['access_token'];

                        // 2. Get Project ID
                        $json = json_decode(file_get_contents($credentialsPath), true);
                        $projectId = $json['project_id'];

                        // 3. Send Notification (v1 syntax)
                        Log::info("Sending FCM v1 to Driver: {$driver->id}");

                        $response = \Illuminate\Support\Facades\Http::withHeaders([
                            'Authorization' => 'Bearer ' . $accessToken,
                            'Content-Type'  => 'application/json',
                        ])->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                            'message' => [
                                'token' => $driver->fcm_token,
                                'notification' => [
                                    'title' => 'Order Baru Masuk! 🚖',
                                    'body' => "Tujuan: $zoneName - Penumpang menunggu.",
                                ],
                                'data' => [
                                    'type' => 'new_order',
                                    'booking_id' => (string)$booking->id,
                                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                                ],
                                'android' => [
                                    'priority' => 'HIGH',
                                    'notification' => [
                                        'channel_id' => 'high_importance_channel',
                                        // 'sound' => 'default', // default_sound: true takes care of this
                                        'default_sound' => true,
                                        'default_vibrate_timings' => true,
                                    ]
                                ]
                            ]
                        ]);

                        Log::info("FCM v1 Response: " . $response->status() . " | " . $response->body());
                    }

                } catch (\Exception $e) {
                    Log::error("FCM v1 Error: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            Log::error("Notifikasi Gagal: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Order berhasil diproses',
            'data'    => $result // Mengembalikan objek booking lengkap
        ], 201);
    }

    
    
    /**
     * Mengambil riwayat transaksi CSO (Bisa difilter tanggal).
     */
    public function getHistory(Request $request)
    {
        $cso = $request->user();
        
        // Mulai Query: Ambil transaksi milik CSO ini
        $query = Transaction::whereHas('booking', function ($q) use ($cso) {
            $q->where('cso_id', $cso->id);
        });

        // --- LOGIKA FILTER TANGGAL ---
        if ($request->filled('start_date') && $request->filled('end_date')) {
            // Jika ada filter, gunakan rentang tanggal tersebut
            $start = $request->start_date . ' 00:00:00';
            $end   = $request->end_date . ' 23:59:59';
            $query->whereBetween('created_at', [$start, $end]);
        } else {
            // Jika TIDAK ada filter, defaultnya tampilkan 50 transaksi terakhir 
            // (agar tidak terlalu berat me-load semua data sejak awal berdiri)
            $query->limit(50);
        }

        $transactions = $query->with([
                'booking.zoneTo:id,name',
                'booking.driver.driverProfile', 
                'booking.cso',
                'booking' // Pastikan relasi booking induk termuat untuk status/phone
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($transactions);
    }


    private function logDriverActivity($userId, $type, $desc)
    {
        try {
            \App\Models\DriverActivity::create([
                'user_id' => $userId,
                'activity_type' => $type,
                'description' => $desc
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Driver Activity Log Error: ' . $e->getMessage());
        }
    }

    /**
     * Mengalihkan sebuah booking ke supir lain.
     * (Sebelumnya route ini menunjuk method yang tidak ada sehingga fitur "Ganti Supir" rusak.)
     */
    public function changeDriver(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'new_driver_id' => 'required|exists:users,id',
        ]);

        $newDriverId = (int) $validated['new_driver_id'];

        // Hanya order yang masih 'Assigned' (belum dijalankan/selesai) yang boleh dialihkan.
        if ($booking->status !== 'Assigned') {
            return response()->json([
                'message' => 'Order ini tidak bisa dialihkan (status: ' . $booking->status . ').'
            ], 422);
        }

        if ($newDriverId === (int) $booking->driver_id) {
            return response()->json(['message' => 'Supir tujuan sama dengan supir saat ini.'], 422);
        }

        // Pastikan supir baru valid (role driver).
        $newDriver = User::where('id', $newDriverId)->where('role', 'driver')->first();
        if (!$newDriver) {
            return response()->json(['message' => 'Supir tujuan tidak valid.'], 422);
        }

        // Supir baru tidak boleh sedang menjalankan order lain.
        $newDriverBusy = Booking::where('driver_id', $newDriverId)
            ->whereIn('status', ['Assigned', 'OnTrip'])
            ->where('id', '!=', $booking->id)
            ->exists();
        if ($newDriverBusy) {
            return response()->json(['message' => 'Supir tujuan sedang menjalankan order lain.'], 422);
        }

        $oldDriverId = (int) $booking->driver_id;

        DB::transaction(function () use ($booking, $newDriverId, $oldDriverId) {
            // Alihkan order ke supir baru (status tetap 'Assigned').
            $booking->update(['driver_id' => $newDriverId]);

            // Keluarkan supir baru dari antrian karena sekarang mendapat order.
            DriverQueue::where('user_id', $newDriverId)->delete();

            // Catat aktivitas untuk kedua supir.
            $this->logDriverActivity($oldDriverId, 'ORDER_REASSIGNED_OUT', 'Order dialihkan ke supir lain oleh CSO');
            $this->logDriverActivity($newDriverId, 'ORDER_REASSIGNED_IN', 'Menerima order alihan dari CSO');
            $this->logDriverActivity($newDriverId, 'QUEUE_LEAVE_ORDER', 'Keluar Antrian (Order Alihan)');
        });

        // Muat ulang relasi untuk response dan notifikasi.
        $booking->load(['driver.driverProfile', 'zoneTo', 'cso', 'transaction']);

        // Beritahu supir baru lewat FCM (di luar transaksi DB agar tidak memperlambat).
        try {
            $zoneName = $booking->zoneTo->name ?? 'Tujuan';
            $this->pushFcmToDriver(
                $booking->driver,
                'Order Dialihkan ke Anda! 🚖',
                "Tujuan: $zoneName - Penumpang menunggu.",
                ['type' => 'new_order', 'booking_id' => (string) $booking->id]
            );
        } catch (\Exception $e) {
            Log::error('Notifikasi ganti supir gagal: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Supir berhasil diganti.',
            'data' => $booking,
        ]);
    }

    /**
     * Mengembalikan URL QRIS perusahaan (diatur oleh Admin) agar app CSO bisa menampilkannya.
     * Di web, nilai ini disuntik via blade (window.companyQrisUrl); app butuh endpoint sendiri.
     */
    public function companyQris()
    {
        $path = Setting::where('key', 'company_qris_path')->value('value');

        return response()->json([
            'company_qris_url' => $path ? asset('storage/' . $path) : null,
        ]);
    }

    /**
     * Helper pengiriman push notification FCM (HTTP v1) ke satu supir.
     */
    private function pushFcmToDriver($driver, string $title, string $body, array $data = [])
    {
        if (!$driver || !$driver->fcm_token) {
            return;
        }

        try {
            $credentialsPath = storage_path('app/firebase_credentials.json');
            if (!file_exists($credentialsPath)) {
                Log::error("FCM Error: Credentials file not found at $credentialsPath");
                return;
            }

            $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
            $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials($scopes, $credentialsPath);
            $accessToken = $credentials->fetchAuthToken(\Google\Auth\HttpHandler\HttpHandlerFactory::build())['access_token'];

            $json = json_decode(file_get_contents($credentialsPath), true);
            $projectId = $json['project_id'];

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ])->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $driver->fcm_token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_merge(['click_action' => 'FLUTTER_NOTIFICATION_CLICK'], $data),
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => [
                            'channel_id' => 'high_importance_channel',
                            'default_sound' => true,
                            'default_vibrate_timings' => true,
                        ],
                    ],
                ],
            ]);

            Log::info("FCM v1 Response: " . $response->status() . " | " . $response->body());
        } catch (\Exception $e) {
            Log::error("FCM v1 Error: " . $e->getMessage());
        }
    }
}