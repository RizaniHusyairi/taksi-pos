<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    // === Terjemahan Logika Zona ===
    public function getZones()
    {
        // Mengambil semua zona kecuali 'Bandara' (jika ada logika seperti itu)
        $zones = Zone::get();
        return response()->json($zones);
    }

    public function getAvailableDrivers()
    {
        $drivers = User::where('role', 'driver')
            ->where('active', true)
            ->whereHas('driverProfile', function ($query) {
                $query->where('status', 'available');
            })
            ->with('driverProfile') // Memuat data profil driver
            ->get();

        return response()->json($drivers);
    }

    public function setDriverStatus(Request $request)
    {
        $request->validate(['status' => 'required|in:available,offline']);
        $user = Auth::user();

        if ($user->role !== 'driver') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $user->driverProfile()->update(['status' => $request->status]);

        return response()->json(['message' => 'Status updated successfully']);
    }

    // === Terjemahan Logika Booking & Transaksi ===
    public function storeBooking(Request $request)
    {
        $validated = $request->validate([
            'driver_id' => 'required|exists:users,id',
            'zone_id'   => 'required|exists:zones,id',
        ]);
        
        $zone = Zone::findOrFail($validated['zone_id']);
        $cso = Auth::user();

        $booking = Booking::create([
            'cso_id'    => $cso->id,
            'driver_id' => $validated['driver_id'],
            'zone_id'   => $zone->id,
            'price'     => $zone->price,
            'status'    => 'Assigned',
        ]);

        // Update status driver menjadi 'ontrip'
        DriverProfile::where('user_id', $validated['driver_id'])->update(['status' => 'ontrip']);

        return response()->json($booking, 201);
    }

    public function completeBooking(Booking $booking)
    {
        // Validasi: hanya driver dari booking ini yang bisa menyelesaikan
        $user = Auth::user();
        if ($user->id !== $booking->driver_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        DB::transaction(function () use ($booking) {
            $booking->update(['status' => 'Completed']);
            $booking->driver->driverProfile()->update(['status' => 'available']);
        });

        return response()->json(['message' => 'Booking completed']);
    }

    public function recordPayment(Request $request)
    {
        $validated = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'method'     => 'required|in:QRIS,CashCSO,CashDriver',
        ]);

        $booking = Booking::findOrFail($validated['booking_id']);
        
        // Mulai transaksi database untuk memastikan konsistensi data
        DB::transaction(function () use ($booking, $validated) {
            // 1. Buat record transaksi
            Transaction::create([
                'booking_id' => $booking->id,
                'method'     => $validated['method'],
                'amount'     => $booking->price,
            ]);

            // 2. Update status booking
            $newStatus = ($validated['method'] === 'CashDriver') ? 'CashDriver' : 'Paid';
            $booking->update(['status' => $newStatus]);
        });
        
        return response()->json(['message' => 'Payment recorded successfully'], 201);
    }

    // === Terjemahan Logika Saldo Driver ===
    public function getDriverBalance()
    {
        $driver = Auth::user();
        if ($driver->role !== 'driver') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Ambil nilai komisi dari settings
        $commissionRate = (float) Setting::where('key', 'commission_rate')->value('value');

        // Hitung total kredit (pemasukan)
        $totalCredits = Transaction::whereHas('booking', function ($query) use ($driver) {
                $query->where('driver_id', $driver->id);
            })
            ->whereIn('method', ['QRIS', 'CashCSO'])
            ->sum('amount');
        
        $driverShare = $totalCredits * (1 - $commissionRate);

        // Hitung total debet (penarikan)
        $totalDebits = $driver->withdrawals()
            ->whereIn('status', ['Approved', 'Paid'])
            ->sum('amount');
            
        $balance = $driverShare - $totalDebits;

        return response()->json(['balance' => round($balance)]);
    }
}
