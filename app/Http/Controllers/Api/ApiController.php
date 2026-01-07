<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Models\Setting; 
use App\Models\User;
use App\Models\Zone;
use App\Models\DriverProfile;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\Withdrawals;
use App\Models\DriverQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;


class ApiController extends Controller
{
    // === Terjemahan Logika Zona ===
    public function adminGetDashboardStats()
    {
        // 1. Hitung Semua Metrik
        $revenueToday = Transaction::whereDate('created_at', today())->sum('amount');
        $transactionsToday = Transaction::whereDate('created_at', today())->count();
        // A. Hitung driver di antrian (Online/Available)
        $driversInQueue = DriverQueue::count();

        // B. Hitung driver yang sedang OnTrip (Ada booking belum selesai)
        // Kita hitung jumlah User ID unik yang memiliki booking aktif
        $driversOnTrip = Booking::whereIn('status', ['Assigned']) // Sesuaikan status on trip di sistemmu
            ->distinct('driver_id')
            ->count('driver_id');

        // Total Aktif = Queue + OnTrip (Asumsi driver on trip otomatis keluar dari queue, jadi tidak double count)
        $activeDrivers = $driversInQueue + $driversOnTrip;
        $pendingWithdrawals = Withdrawals::where('status', 'Pending')->count();

        // 2. Siapkan Data Grafik Mingguan (7 hari terakhir)
        $weeklyChartData = Transaction::select(
                DB::raw("DATE(created_at) as label"),
                DB::raw('SUM(amount) as total')
            )
            ->whereBetween('created_at', [now()->subDays(6), now()])
            ->groupBy('label')
            ->orderBy('label', 'asc')
            ->get();

        // 3. Siapkan Data Grafik Bulanan (12 bulan terakhir)
        $monthlyChartData = Transaction::select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as label"),
                DB::raw('SUM(amount) as total')
            )
            ->whereBetween('created_at', [now()->subMonths(11)->startOfMonth(), now()])
            ->groupBy('label')
            ->orderBy('label', 'asc')
            ->get();

        // 4. Gabungkan semua data dalam satu respons JSON
        return response()->json([
            'metrics' => [
                'revenue_today' => $revenueToday,
                'transactions_today' => $transactionsToday,
                'active_drivers' => $activeDrivers,
                'pending_withdrawals' => $pendingWithdrawals,
            ],
            'charts' => [
                'weekly' => [
                    'labels' => $weeklyChartData->pluck('label'),
                    'values' => $weeklyChartData->pluck('total'),
                ],
                'monthly' => [
                    'labels' => $monthlyChartData->pluck('label'),
                    'values' => $monthlyChartData->pluck('total'),
                ],
            ]
        ]);
    }

    public function getZones()
    {
        // Mengambil semua zona kecuali 'Bandara' (jika ada logika seperti itu)
        $zones = Zone::get();
        return response()->json($zones);
    }

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
                // Kita kembalikan format User object agar frontend tidak perlu berubah banyak
                $user = $queue->driver;
                // Pastikan status di profile sinkron (opsional visual)
                if($user->driverProfile) {
                    $user->driverProfile->status = 'available'; 
                }
                return $user;
            });

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

    // Ambil semua withdrawal request dengan data supirnya
    public function adminGetWithdrawals()
    {
        $withdrawals = Withdrawals::with('driver.driverProfile') 
                                ->orderBy('requested_at', 'desc')
                                ->get();
        return response()->json($withdrawals);
    }

    // Setujui permintaan
    // [UBAH INI] Setujui Permintaan + Upload Bukti (Satu Langkah)
    public function adminApproveWithdrawal(Request $request, Withdrawals $withdrawal)
    {
        $request->validate([
            'proof_image' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        if ($request->hasFile('proof_image')) {
            $path = $request->file('proof_image')->store('proofs', 'public');
            
            DB::transaction(function () use ($withdrawal, $path) {
                // 1. Update status Withdrawal
                $withdrawal->update([
                    'status' => 'Approved', 
                    'processed_at' => now(),
                    'proof_image' => $path
                ]);

                // 2. [BARU] Update Status Transaksi Terkait menjadi 'Paid' (Lunas/Cair)
                Transaction::where('withdrawal_id', $withdrawal->id)
                    ->update(['payout_status' => 'Paid']);
            });
            
            return response()->json([
                'message' => 'Permintaan disetujui, bukti terupload, dan status transaksi diperbarui.',
                'path' => $path
            ]);
        }

        return response()->json(['message' => 'Gagal mengupload bukti.'], 400);
    }

    // Tolak permintaan
    // === Update pada adminRejectWithdrawal ===
    public function adminRejectWithdrawal(Withdrawals $withdrawal)
    {
        DB::transaction(function () use ($withdrawal) {
            // 1. Update Withdrawal
            $withdrawal->update(['status' => 'Rejected', 'processed_at' => now()]);

            // 2. [BARU] Kembalikan Status Transaksi ke 'Unpaid' dan lepas kaitannya
            Transaction::where('withdrawal_id', $withdrawal->id)
                ->update([
                    'payout_status' => 'Unpaid',
                    'withdrawal_id' => null // Lepas ikatan agar bisa diajukan lagi nanti
                ]);
        });

        return response()->json(['message' => 'Permintaan ditolak dan saldo dikembalikan ke Unpaid.']);
    }

    // === [METHOD BARU] Ambil Detail Transaksi dalam sebuah Withdrawal ===
    public function adminGetWithdrawalDetails(Withdrawals $withdrawal)
    {
        // Ambil transaksi yang withdrawal_id nya sesuai dengan id penarikan ini
        $transactions = Transaction::with(['booking.zoneTo'])
            ->where('withdrawal_id', $withdrawal->id)
            ->get();

        return response()->json($transactions);
    }

    // Anda juga bisa menambahkan metode untuk 'Mark as Paid' jika logikanya berbeda
    

    public function adminGetRevenueReport(Request $request)
    {
        $range = $request->query('range', 'daily'); // default 'daily'
        $endDate = now();
        $data = [];

        switch ($range) {
            case 'weekly':
                // 8 minggu terakhir
                $startDate = now()->subWeeks(8)->startOfWeek();
                $data = Transaction::select(
                        DB::raw("DATE_FORMAT(created_at, '%x-W%v') as label"), // Format: YYYY-WW (e.g., 2025-W36)
                        DB::raw('SUM(amount) as total')
                    )
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->groupBy('label')
                    ->orderBy('label', 'asc')
                    ->get();
                break;

            case 'monthly':
                // 12 bulan terakhir
                $startDate = now()->subMonths(12)->startOfMonth();
                $data = Transaction::select(
                        DB::raw("DATE_FORMAT(created_at, '%Y-%m') as label"), // Format: YYYY-MM
                        DB::raw('SUM(amount) as total')
                    )
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->groupBy('label')
                    ->orderBy('label', 'asc')
                    ->get();
                break;

            case 'daily':
            default:
                // 7 hari terakhir
                $startDate = now()->subDays(7);
                $data = Transaction::select(
                        DB::raw("DATE(created_at) as label"),
                        DB::raw('SUM(amount) as total')
                    )
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->groupBy('label')
                    ->orderBy('label', 'asc')
                    ->get();
                break;
        }

        // Ubah format menjadi yang dibutuhkan oleh Chart.js
        $response = [
            'labels' => $data->pluck('label'),
            'values' => $data->pluck('total'),
        ];

        return response()->json($response);
    }

    public function adminGetDriverPerformanceReport(Request $request)
    {
        $sortBy = $request->query('sort_by', 'trips'); // default 'trips'

        $drivers = User::where('role', 'driver')
            // Menghitung jumlah booking dengan status 'Completed' secara efisien
            ->withCount(['bookings as trips' => function ($query) {
                $query->where('status', 'Completed');
            }])
            // Menjumlahkan total pendapatan dari tabel transaksi
            ->withSum('transactions as revenue', 'amount')
            // Mengurutkan berdasarkan parameter yang diberikan
            ->orderBy($sortBy === 'revenue' ? 'revenue' : 'trips', 'desc')
            ->get();

        // Data yang dikembalikan sudah jadi dan terurut
        return response()->json($drivers);
    }
    public function adminGetSettings()
    {
        // Mengambil semua settings dan mengubahnya menjadi format key => value
        // Contoh hasil: { "commission_rate": "0.20", "admin_email": "admin@example.com" }
        $settings = Setting::all()->pluck('value', 'key');
        return response()->json($settings);
    }
    
    public function adminUpdateSettings(Request $request)
    {
        // Validasi input jika perlu
        $validated = $request->validate([
            'commission_rate' => 'required|numeric|min:0|max:100',
            // Tambahkan validasi untuk setting lain jika ada
        ]);
    
        // Loop melalui data yang dikirim dan update ke database
        foreach ($validated as $key => $value) {
            // Konversi dari persen kembali ke desimal sebelum disimpan
            $dbValue = $key === 'commission_rate' ? $value / 100 : $value;
            
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $dbValue]
            );
        }
    
        return response()->json(['message' => 'Pengaturan berhasil disimpan.']);
    }

    public function adminGetUsersByRole($role)
    {
        // Validasi untuk keamanan
        if (!in_array($role, ['driver', 'cso'])) {
            return response()->json(['message' => 'Role tidak valid'], 400);
        }

        $users = User::where('role', $role)
                    ->where('active', true) // Mungkin Anda hanya ingin menampilkan yang aktif
                    ->select('id', 'name')
                    ->orderBy('name')
                    ->get();
                    
        return response()->json($users);
    }
}

