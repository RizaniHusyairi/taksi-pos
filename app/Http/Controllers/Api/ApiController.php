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

    // =========================================================
    // === METODE BARU: Untuk Panel Admin ===
    // =========================================================

    // --- Manajemen Zona ---
    public function adminGetZones() {
        return response()->json(Zone::orderBy('name')->get());
    }

    public function adminStoreZone(Request $request) {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
        ]);
        $zone = Zone::create($validated);
        return response()->json($zone, 201);
    }

    public function adminUpdateZone(Request $request, Zone $zone) {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
        ]);
        $zone->update($validated);
        return response()->json($zone);
    }

    public function adminDestroyZone(Zone $zone) {
        $zone->delete();
        return response()->json(['message' => 'Zone deleted successfully']);
    }

    // --- Manajemen Pengguna ---
    public function adminGetUsers() {
        // Mengambil semua user dengan relasi driverProfile jika ada
        return response()->json(User::with('driverProfile')->orderBy('name')->get());
    }

    public function adminStoreUser(Request $request) {
        $validated = $request->validate([
            'name' => 'required|string',
            'username' => 'required|string|unique:users,username',
            'password' => 'required|string|min:6',
            'role' => 'required|in:cso,driver,admin',
            // validasi tambahan untuk supir
            'car_model' => 'nullable|string',
            'plate_number' => 'nullable|string',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        if ($validated['role'] === 'driver') {
            $user->driverProfile()->create([
                'car' => $validated['car_model'],
                'plate' => $validated['plate_number'],
                'status' => 'offline', // default status
            ]);
        }

        return response()->json($user->load('driverProfile'), 201);
    }

    public function adminUpdateUser(Request $request, User $user) {
        $validated = $request->validate([
            'name' => 'required|string',
            'password' => 'nullable|string|min:6',
            'role' => 'required|in:cso,driver,admin',
            // validasi tambahan untuk supir
            'car_model' => 'nullable|string',
            'plate_number' => 'nullable|string',
        ]);

        $user->update([
            'name' => $validated['name'],
            'role' => $validated['role'],
        ]);

        if (!empty($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        if ($validated['role'] === 'driver') {
            $user->driverProfile()->updateOrCreate([], [
                'car' => $validated['car_model'],
                'plate' => $validated['plate_number'],
            ]);
        } else {
            // Jika bukan driver, hapus profil driver jika ada
            $user->driverProfile()->delete();
        }

        return response()->json($user->load('driverProfile'));
    }

    public function adminToggleUserActive(User $user) {
        $user->update(['active' => !$user->active]);
        return response()->json(['message' => 'User status updated', 'active' => $user->active]);
    }


    public function adminGetTransactions(Request $request)
    {
        // Mulai query dengan eager loading untuk data terkait
        $query = Transaction::with(['booking.zoneTo', 'driver', 'cso'])
                            ->orderBy('created_at', 'desc');

        // Terapkan filter berdasarkan query string dari URL
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }
        if ($request->has('driver_id')) {
            // Filter berdasarkan relasi booking
            $driverId = $request->query('driver_id');
            $query->whereHas('booking', function ($q) use ($driverId) {
                $q->where('driver_id', $driverId);
            });
        }
        if ($request->has('cso_id')) {
            // Filter berdasarkan relasi booking
            $csoId = $request->query('cso_id');
            $query->whereHas('booking', function ($q) use ($csoId) {
                $q->where('cso_id', $csoId);
            });
        }

        $transactions = $query->paginate(50); // Gunakan paginasi untuk data yang banyak

        return response()->json($transactions);
    }

    // app/Http/Controllers/Api/ApiController.php

    // Ambil semua withdrawal request dengan data supirnya
    public function adminGetWithdrawals()
    {
        $withdrawals = Withdrawal::with('driver') // Eager load relasi 'driver'
                                ->orderBy('requested_at', 'desc')
                                ->get();
        return response()->json($withdrawals);
    }

    // Setujui permintaan
    public function adminApproveWithdrawal(Withdrawal $withdrawal)
    {
        $withdrawal->update(['status' => 'Approved', 'processed_at' => now()]);
        return response()->json(['message' => 'Withdrawal approved']);
    }

    // Tolak permintaan
    public function adminRejectWithdrawal(Withdrawal $withdrawal)
    {
        $withdrawal->update(['status' => 'Rejected', 'processed_at' => now()]);
        return response()->json(['message' => 'Withdrawal rejected']);
    }

    // Anda juga bisa menambahkan metode untuk 'Mark as Paid' jika logikanya berbeda
    public function adminMarkAsPaid(Withdrawal $withdrawal)
    {
        // Hanya bisa di-set Paid jika status sebelumnya Approved
        if ($withdrawal->status !== 'Approved') {
            return response()->json(['message' => 'Hanya permintaan yang sudah disetujui yang bisa ditandai lunas.'], 422);
        }
        $withdrawal->update(['status' => 'Paid', 'processed_at' => now()]);
        return response()->json(['message' => 'Withdrawal marked as paid']);
    }
}
