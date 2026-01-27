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
            return $booking->load(['driver.driverProfile', 'zoneTo', 'cso']);
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

            // --- D. NOTIFIKASI FCM KE DRIVER APP ---
            if ($driver->fcm_token) {
                try {
                    // Gunakan FCM Legacy API atau HTTP v1 jika sudah dikonfigurasi
                    // Di sini kita gunakan Legacy API untuk kemudahan implementasi cepat
                    // Pastikan key ada di .env: FCM_SERVER_KEY
                    $fcmKey = env('FCM_SERVER_KEY');
                    
                    if ($fcmKey) {
                        \Illuminate\Support\Facades\Http::withHeaders([
                            'Authorization' => 'key=' . $fcmKey,
                            'Content-Type'  => 'application/json',
                        ])->post('https://fcm.googleapis.com/fcm/send', [
                            'to' => $driver->fcm_token,
                            'notification' => [
                                'title' => 'Order Baru Masuk! 🚖',
                                'body' => "Tujuan: $zoneName - Penumpang menunggu.",
                                'sound' => 'default',
                                'priority' => 'high',
                            ],
                            'data' => [
                                'type' => 'new_order',
                                'booking_id' => (string) $result->id,
                                'zone_price' => (string) $result->price,
                                'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
                            ]
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error("FCM Error: " . $e->getMessage());
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


}