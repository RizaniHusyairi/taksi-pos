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

class CsoApiController extends Controller
{
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
        ], [
            'payment_proof.required_if' => 'Wajib upload foto bukti transfer untuk QRIS.',
        ]);

        $zone = Zone::findOrFail($validated['zone_id']);
        $cso = Auth::user();

        // 2. Mulai Transaksi Database (Atomic)
        $result = DB::transaction(function () use ($validated, $zone, $cso, $request) {
            
            // A. Tentukan Status Awal
            // Jika CashDriver -> Status 'CashDriver' (Belum setor ke kantor)
            // Jika QRIS/CashCSO -> Status 'Paid' (Uang sudah masuk)
            $status = ($validated['method'] === 'CashDriver') ? 'CashDriver' : 'Paid';

            // B. Simpan Data Booking
            $booking = Booking::create([
                'cso_id'    => $cso->id,
                'driver_id' => $validated['driver_id'],
                'zone_id'   => $zone->id,
                'price'     => $zone->price,
                'status'    => $status, 
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
            return $booking->load(['driver', 'zoneTo', 'cso']);
        });

        return response()->json([
            'message' => 'Order berhasil diproses',
            'data'    => $result // Mengembalikan objek booking lengkap
        ], 201);
    }

    
    /**
     * Mengambil riwayat transaksi hari ini untuk CSO yang sedang login.
     */
    public function getHistory(Request $request)
    {
        $cso = $request->user();

        $transactions = Transaction::whereHas('booking', function ($query) use ($cso) {
                $query->where('cso_id', $cso->id);
            })
            ->whereDate('created_at', today())
            ->with([
                'booking.zoneTo:id,name', // Ambil nama zona tujuan
                'booking.driver:id,name' // Ambil nama supir
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($transactions);
    }
}