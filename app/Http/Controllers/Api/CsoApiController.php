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
        $drivers = User::where('role', 'driver')
            ->where('active', true)
            ->whereHas('driverProfile', function ($query) {
                $query->where('status', 'available');
            })
            ->with('driverProfile:user_id,car_model,plate_number,status') // Hanya ambil data yang perlu
            ->select('id', 'name')
            ->get();

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

            // 2. Update status driver menjadi 'ontrip'
            DriverProfile::where('user_id', $validated['driver_id'])->update(['status' => 'ontrip']);

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
        ]);

        $booking = Booking::findOrFail($validated['booking_id']);

        // Fungsi ini sekarang HANYA membuat record transaksi
        Transaction::create([
            'booking_id' => $booking->id,
            'method'     => $validated['method'],
            'amount'     => $booking->price,
        ]);
        
        // Status booking TIDAK diubah. Biarkan tetap 'Assigned'.

        return response()->json(['message' => 'Payment recorded successfully'], 201);
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
                'booking.driver:id,name'          // Ambil nama supir
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($transactions);
    }
}