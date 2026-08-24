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
use App\Models\CsoDeposit;
use App\Models\DriverProfile;
use App\Models\DriverQueue; 
use App\Models\Setting; 
use Illuminate\Support\Facades\Hash;
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
     * Antrian supir yang BENAR-BENAR siap menerima order, terurut giliran:
     * sort_order ASC (0,1,2 ... 1000+ = rejoin), lalu created_at ASC
     * (sesama sort_order: siapa cepat dia dapat).
     *
     * Satu-satunya definisi "giliran berikutnya" — dipakai getAvailableDrivers(),
     * processOrder(), dan changeDriver() supaya tidak ada dua versi kriteria.
     */
    private function readyQueueQuery()
    {
        return DriverQueue::with(['driver.driverProfile'])
            ->whereHas('driver.driverProfile', function ($query) {
                // Hanya driver yang BENAR-BENAR siap: sudah tiba & standby, dan tidak
                // sedang di luar area. Cegah order jatuh ke driver yang belum datang
                // (mis. hasil pre-fill rotasi harian yang masih offline & lat/lng 0).
                $query->whereIn('status', ['standby', 'available'])
                      ->whereNull('out_of_area_since')
                      // ...DAN masih mengirim kabar. Tanpa syarat ini, supir
                      // yang mematikan aplikasi lalu pulang tetap memegang
                      // gilirannya: `out_of_area_since` hanya terisi kalau ada
                      // ping, jadi HP yang diam terlihat sama seperti supir
                      // yang setia menunggu di bandara. Aplikasi mengirim
                      // heartbeat tiap 75 detik, jadi ambang menit-an ini tidak
                      // akan mengganggu supir yang benar-benar hadir.
                      ->whereNotNull('location_updated_at')
                      ->where('location_updated_at', '>=', now()->subMinutes(
                          (int) config('taksi.driver_queue.stale_location_minutes')
                      ));
            })
            ->orderBy('sort_order', 'asc')
            ->orderBy('created_at', 'asc');
    }

    /**
     * Supir yang sedang mendapat giliran (antrian teratas), atau null bila
     * antrian kosong.
     */
    private function nextInQueueDriverId(): ?int
    {
        $top = $this->readyQueueQuery()->first();
        return $top ? (int) $top->user_id : null;
    }

    /**
     * Mengambil daftar supir yang statusnya 'available'.
     */
    public function getAvailableDrivers()
    {
        $drivers = $this->readyQueueQuery()
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

        // Tandai siapa yang sedang mendapat giliran. Backend-lah penentunya —
        // UI (web & mobile) tinggal membaca flag ini, tidak menebak sendiri.
        // Flag ditaruh di level teratas objek user (sejajar id/name), BUKAN di
        // dalam driver_profile, agar tidak mengulang kebingungan queue_score.
        $drivers->each(function ($user, $i) {
            $user->is_next = ($i === 0);
        });

        return response()->json($drivers);
    }

    /**
     * Lokasi para supir untuk peta dashboard CSO.
     * Sumber posisi: tabel driver_profiles (lokasi terakhir tiap supir).
     * Menampilkan supir yang sedang AKTIF — standby (menunggu) maupun OnTrip
     * (mengantar) — sehingga supir yang sedang jalan tetap terlihat & terlacak,
     * walaupun sudah keluar dari tabel antrian.
     * Juga mengembalikan titik bandara + radius untuk pusat & lingkaran peta.
     */
    public function getDriverLocations()
    {
        // Posisi antrian (sort_order) hanya ada untuk supir yang masih mengantri.
        // Supir OnTrip tidak punya — biarkan null.
        $queueScores = DriverQueue::pluck('sort_order', 'user_id');

        $drivers = \App\Models\DriverProfile::with('user:id,name')
            ->whereIn('status', ['standby', 'ontrip'])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('latitude', '!=', 0)
            ->where('longitude', '!=', 0)
            ->get()
            ->map(function ($p) use ($queueScores) {
                $user = $p->user;
                if (!$user) {
                    return null;
                }

                return [
                    'id'           => $user->id,
                    'name'         => $user->name,
                    'line_number'  => $p->line_number,
                    'car_model'    => $p->car_model,
                    'plate_number' => $p->plate_number,
                    'status'       => $p->status,
                    'queue_score'  => $queueScores[$user->id] ?? null,
                    'latitude'     => (float) $p->latitude,
                    'longitude'    => (float) $p->longitude,
                    'updated_at'   => optional($p->location_updated_at)->toIso8601String(),
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'base' => [
                'latitude'  => Setting::airportLatitude(),
                'longitude' => Setting::airportLongitude(),
                'radius_km' => Setting::airportRadiusKm(),
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

        // Agregasi di DB (1 query) — tidak menarik semua transaksi hari ini ke memori.
        $todayAgg = (clone $base)->whereDate('created_at', $today)
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw('COALESCE(SUM(amount), 0) as revenue')
            ->selectRaw("COALESCE(SUM(CASE WHEN method IN ('CashCSO','CashDriver') THEN amount ELSE 0 END), 0) as cash")
            ->selectRaw("COALESCE(SUM(CASE WHEN method = 'QRIS' THEN amount ELSE 0 END), 0) as qris")
            ->first();

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
                'count'   => (int) $todayAgg->cnt,
                'revenue' => (float) $todayAgg->revenue,
                'cash'    => (float) $todayAgg->cash,
                'qris'    => (float) $todayAgg->qris,
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

        // Supir yang seharusnya mendapat giliran. Kalau CSO memilih supir lain,
        // order tetap diproses (ada kasus lapangan: mobil mogok, penumpang
        // menolak, supir tak muncul) tetapi dicatat sebagai audit di bawah.
        $topDriverId  = $this->nextInQueueDriverId();
        $isOverride   = $topDriverId !== null && $topDriverId !== (int) $validated['driver_id'];
        $topDriverName = $isOverride
            ? (User::find($topDriverId)->name ?? 'supir teratas')
            : null;

        // 2. Mulai Transaksi Database (Atomic)
        $result = DB::transaction(function () use ($validated, $zone, $cso, $request, $isOverride, $topDriverId, $topDriverName) {
            
            
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

            // E. Jejak aktivitas supir.
            $driverId = (int) $validated['driver_id'];
            $this->logDriverActivity($driverId, 'ORDER_RECEIVED', "Dapat order dari CSO {$cso->name} → {$zone->name}");
            $this->logDriverActivity($driverId, 'QUEUE_LEAVE_ORDER', 'Keluar Antrian (Order)');

            // Bila CSO melewati giliran, catat di KEDUA sisi agar bisa ditelusuri
            // dari halaman aktivitas supir manapun.
            if ($isOverride) {
                $chosenName = User::find($driverId)->name ?? 'supir lain';
                $this->logDriverActivity(
                    $driverId,
                    'ORDER_QUEUE_OVERRIDE',
                    "Mendahului antrian (giliran: {$topDriverName})"
                );
                $this->logDriverActivity(
                    $topDriverId,
                    'QUEUE_SKIPPED',
                    "Dilewati CSO {$cso->name} — order diberikan ke {$chosenName}"
                );
            }

            // Load data lengkap untuk dikembalikan ke frontend (guna cetak struk)
            // 'transaction' diikutkan agar app bisa membangun link/QR struk dari ID transaksi.
            return $booking->load(['driver.driverProfile', 'zoneTo', 'cso', 'transaction']);
        });

        // 3. LOGIKA NOTIFIKASI (Di luar transaction DB agar tidak lambat)
        try {
            $driver = $result->driver;
            $waToken = Setting::getValue('wa_token');
            
            // Link Struk (menggunakan ID transaksi)
            $receiptUrl = route('receipt.show', $result->transaction->receipt_token);
            
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
            \App\Jobs\SendWhatsAppMessage::dispatch($validated['passenger_phone'], $msgPassenger, $waToken);
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

                \App\Jobs\SendWhatsAppMessage::dispatch($driverPhone, $msgDriver, $waToken);
            }

            // --- C. KIRIM EMAIL KE DRIVER ---
            if ($driver->email) {
                // Pastikan Anda sudah membuat Mail Class: php artisan make:mail NewOrderForDriver
                Mail::to($driver->email)->send(new \App\Mail\NewOrderForDriver($result, $receiptUrl));
            }

            // --- D. NOTIFIKASI FCM KE DRIVER APP (async + retry via queue) ---
            $methodLabel = $this->paymentMethodLabel($validated['method']);
            $this->pushFcmToDriver(
                $driver,
                'Order Baru Masuk! 🚖',
                "Tujuan: {$zoneName} · Tarif Rp {$priceRp} · {$methodLabel}",
                [
                    'type'        => 'new_order',
                    'booking_id'  => (string) $result->id,
                    'destination' => $zoneName,
                    'fare'        => $priceRp,
                    'method'      => $methodLabel,
                ]
            );
        } catch (\Exception $e) {
            Log::error("Notifikasi Gagal: " . $e->getMessage());
        }

        return response()->json([
            'message'        => 'Order berhasil diproses',
            'queue_override' => $isOverride, // true = giliran antrian dilewati
            'data'           => $result // Mengembalikan objek booking lengkap
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

        // "Ganti Supir" adalah jalur kedua yang bisa melewati giliran — audit
        // sama seperti processOrder().
        $cso           = Auth::user();
        $topDriverId   = $this->nextInQueueDriverId();
        $isOverride    = $topDriverId !== null && $topDriverId !== $newDriverId;
        $topDriverName = $isOverride ? (User::find($topDriverId)->name ?? 'supir teratas') : null;

        DB::transaction(function () use ($booking, $newDriverId, $oldDriverId, $newDriver, $cso, $isOverride, $topDriverId, $topDriverName) {
            // Alihkan order ke supir baru (status tetap 'Assigned').
            $booking->update(['driver_id' => $newDriverId]);

            // Keluarkan supir baru dari antrian karena sekarang mendapat order.
            DriverQueue::where('user_id', $newDriverId)->delete();

            // Catat aktivitas untuk kedua supir.
            $this->logDriverActivity($oldDriverId, 'ORDER_REASSIGNED_OUT', 'Order dialihkan ke supir lain oleh CSO');
            $this->logDriverActivity($newDriverId, 'ORDER_REASSIGNED_IN', 'Menerima order alihan dari CSO');
            $this->logDriverActivity($newDriverId, 'QUEUE_LEAVE_ORDER', 'Keluar Antrian (Order Alihan)');

            if ($isOverride) {
                $this->logDriverActivity(
                    $newDriverId,
                    'ORDER_QUEUE_OVERRIDE',
                    "Mendahului antrian (giliran: {$topDriverName})"
                );
                $this->logDriverActivity(
                    $topDriverId,
                    'QUEUE_SKIPPED',
                    "Dilewati CSO {$cso->name} — order alihan diberikan ke {$newDriver->name}"
                );
            }
        });

        // Muat ulang relasi untuk response dan notifikasi.
        $booking->load(['driver.driverProfile', 'zoneTo', 'cso', 'transaction']);

        // Beritahu supir baru lewat FCM (di luar transaksi DB agar tidak memperlambat).
        try {
            $zoneName = $booking->zoneTo->name ?? 'Tujuan';
            $priceRp = number_format($booking->price, 0, ',', '.');
            $methodLabel = $this->paymentMethodLabel($booking->transaction->method ?? 'QRIS');
            $this->pushFcmToDriver(
                $booking->driver,
                'Order Dialihkan ke Anda! 🚖',
                "Tujuan: {$zoneName} · Tarif Rp {$priceRp} · {$methodLabel}",
                [
                    'type'        => 'new_order',
                    'booking_id'  => (string) $booking->id,
                    'destination' => $zoneName,
                    'fare'        => $priceRp,
                    'method'      => $methodLabel,
                ]
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
        $path = Setting::getValue('company_qris_path');

        return response()->json([
            'company_qris_url' => $path ? asset('storage/' . $path) : null,
        ]);
    }

    /**
     * Label metode pembayaran yang ramah dibaca supir di notifikasi.
     */
    private function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            'CashDriver' => 'Tunai ke Supir',
            'CashCSO'    => 'Tunai ke Kasir',
            default      => 'QRIS',
        };
    }

    // =====================================================================
    // ===  SETORAN TUNAI CSO -> ADMIN                                    ===
    // =====================================================================

    /**
     * Rekap tunai yang MASIH dipegang CSO, dikelompokkan per tanggal.
     *
     * CSO menyetor per hari penuh (boleh beberapa hari sekaligus), jadi inilah
     * daftar yang ia centang di aplikasi. Diagregasi di DB — jangan tarik
     * semua transaksi ke memori, bandingkan getDashboardStats().
     */
    public function depositOutstanding(Request $request)
    {
        $cso = $request->user();

        $rows = Transaction::cashCsoBelumSetor($cso->id)
            ->selectRaw('DATE(created_at) as tanggal')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->selectRaw('COUNT(*) as jumlah')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderByDesc(DB::raw('DATE(created_at)'))
            ->get();

        return response()->json([
            'days' => $rows->map(fn ($r) => [
                'date'  => (string) $r->tanggal,
                'total' => (float) $r->total,
                'count' => (int) $r->jumlah,
            ])->values(),
            'grand_total' => (float) $rows->sum('total'),
            'grand_count' => (int) $rows->sum('jumlah'),
        ]);
    }

    /**
     * Buat setoran atas satu/beberapa tanggal.
     *
     * Nominal TIDAK diterima dari klien: server mengunci sendiri transaksi yang
     * memenuhi syarat lalu menjumlahkannya. Inilah yang membuat dua perangkat
     * yang menekan "Setor" bersamaan tidak bisa menyetor uang yang sama dua
     * kali — yang kedua hanya kebagian sisa, atau ditolak karena kosong.
     */
    public function storeDeposit(Request $request)
    {
        $validated = $request->validate([
            'dates'       => 'required|array|min:1|max:62',
            'dates.*'     => 'required|date_format:Y-m-d',
            'note'        => 'nullable|string|max:500',
            'proof_image' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
        ], [
            'dates.required' => 'Pilih minimal satu tanggal setoran.',
        ]);

        $cso   = $request->user();
        $dates = array_values(array_unique($validated['dates']));

        // Foto disimpan SEBELUM transaksi DB supaya kegagalan upload tidak
        // menyisakan setoran setengah jadi (pola sama dengan recordPayment).
        $proofPath = $request->hasFile('proof_image')
            ? $request->file('proof_image')->store('deposit_proofs', 'public')
            : null;

        $hasil = DB::transaction(function () use ($cso, $dates, $validated, $proofPath) {
            $placeholders = implode(',', array_fill(0, count($dates), '?'));

            $rows = Transaction::cashCsoBelumSetor($cso->id)
                ->whereRaw("DATE(created_at) IN ($placeholders)", $dates)
                ->lockForUpdate()
                ->get(['id', 'amount', 'created_at']);

            if ($rows->isEmpty()) {
                return ['error' => 'Tidak ada tunai yang perlu disetor pada tanggal tersebut.'];
            }

            // period_dates diambil dari baris yang BENAR-BENAR terkunci, bukan
            // dari permintaan klien — tanggal kosong tidak ikut tercatat.
            $tanggalNyata = $rows
                ->map(fn ($t) => $t->created_at->toDateString())
                ->unique()->sort()->values()->all();

            $deposit = CsoDeposit::create([
                'cso_id'             => $cso->id,
                'amount'             => $rows->sum('amount'),
                'status'             => 'Pending',
                'period_dates'       => $tanggalNyata,
                'transactions_count' => $rows->count(),
                'note'               => $validated['note'] ?? null,
                'proof_image'        => $proofPath,
                'submitted_at'       => now(),
            ]);

            Transaction::whereIn('id', $rows->pluck('id'))->update([
                'deposit_status' => 'Processing',
                'cso_deposit_id' => $deposit->id,
            ]);

            return ['deposit' => $deposit];
        });

        if (isset($hasil['error'])) {
            return response()->json(['message' => $hasil['error']], 422);
        }

        return response()->json([
            'message' => 'Setoran diajukan, menunggu verifikasi admin.',
            'data'    => $hasil['deposit'],
        ], 201);
    }

    /** Riwayat setoran milik CSO yang sedang login. */
    public function depositHistory(Request $request)
    {
        return response()->json(
            CsoDeposit::where('cso_id', $request->user()->id)
                ->orderByDesc('submitted_at')
                ->paginate(20)
        );
    }

    /** Detail satu setoran + transaksi yang tercakup di dalamnya. */
    public function depositDetail(Request $request, CsoDeposit $deposit)
    {
        // Route model binding TIDAK memeriksa kepemilikan — tanpa baris ini,
        // CSO mana pun bisa membaca setoran CSO lain hanya dengan menebak id.
        if ((int) $deposit->cso_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Setoran ini bukan milik Anda.'], 403);
        }

        $deposit->load(['transactions.booking.zoneTo:id,name', 'transactions.booking.driver:id,name']);

        return response()->json($deposit);
    }

    /**
     * Helper pengiriman push notification FCM (HTTP v1) ke satu supir.
     */
    private function pushFcmToDriver($driver, string $title, string $body, array $data = [])
    {
        if (!$driver || !$driver->fcm_token) {
            return;
        }

        // Async + retry via queue (lihat App\Jobs\SendFcmNotification).
        \App\Jobs\SendFcmNotification::dispatch($driver->fcm_token, $title, $body, $data);
    }
}