<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\WhatsAppService;
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
use Illuminate\Support\Facades\Mail;
use App\Mail\WithdrawalApprovedNotification;

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
        $query = Transaction::with(['booking.zoneTo', 
            'booking.driver', // Ambil driver lewat booking
            'booking.cso'     // Ambil cso lewat booking
            ])->orderBy('created_at', 'desc');

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

            // 2. KIRIM NOTIFIKASI (Di luar Transaction DB agar tidak rollback jika email gagal)
            $driver = $withdrawal->driver;
            
            // --- KIRIM EMAIL ---
            try {
                // Pastikan driver punya email valid
                if ($driver->email) {
                    Mail::to($driver->email)->send(new WithdrawalApprovedNotification($withdrawal));
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Gagal kirim email ke driver: ' . $e->getMessage());
            }

            // --- KIRIM WHATSAPP ---
            try {
                $waToken = Setting::where('key', 'wa_token')->value('value');
                
                // Pastikan driver punya nomor HP (sesuaikan nama kolom di DB Anda, misal: phone_number)
                // Jika Anda menyimpan no HP di tabel driver_profiles, sesuaikan kodenya.
                // Asumsi: No HP ada di tabel users kolom 'username' (jika username pakai no HP) atau kolom baru 'phone_number'
                $driverPhone = $driver->phone_number ?? $driver->username; 

                if ($waToken && $driverPhone) {
                    $amountRp = number_format($withdrawal->amount, 0, ',', '.');
                    $date = now()->format('d M Y H:i');
                    
                    $message = "*PENCAIRAN DANA BERHASIL*\n\n"
                        . "Halo $driver->name,\n\n"
                        . "Pengajuan pencairan dana Anda sebesar *Rp $amountRp* telah DISETUJUI dan DITRANSFER oleh admin.\n\n"
                        . "📅 Waktu: $date\n"
                        . "🏦 Bank: Bank BTN\n\n"
                        . "Silakan cek rekening Anda. Terima kasih!";

                    WhatsAppService::send($driverPhone, $message, $waToken);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Gagal kirim WA ke driver: ' . $e->getMessage());
            }
            
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
        // Mengambil semua settings
        $settings = Setting::all()->pluck('value', 'key');
        
        // Format URL untuk gambar
        if (isset($settings['company_qris_path'])) {
            $settings['company_qris_url'] = asset('storage/' . $settings['company_qris_path']);
        }

        return response()->json($settings);
    }
    
    public function adminUpdateSettings(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('adminUpdateSettings hit', $request->all());

        // Validasi input
        $validated = $request->validate([
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'admin_email'     => 'nullable|email|max:255',
            'company_qris'    => 'nullable|image|mimes:jpeg,png,jpg|max:2048', // Validasi File
            'mail_host'         => 'nullable|string',
            'mail_port'         => 'nullable|numeric',
            'mail_username'     => 'nullable|string',
            'mail_password'     => 'nullable|string',
            'mail_encryption'   => 'nullable|string|in:tls,ssl,null',
            'mail_from_address' => 'nullable|email',
            'mail_from_name'    => 'nullable|string',
            'wa_token'          => 'nullable|string',
            'admin_wa_number'   => 'nullable|string',
        ]);
    
        // 1. Handle File Upload (QRIS)
        if ($request->hasFile('company_qris')) {
            \Illuminate\Support\Facades\Log::info('File company_qris found');
            try {
                // Hapus file lama jika ada
                $oldPath = Setting::where('key', 'company_qris_path')->value('value');
                if ($oldPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($oldPath)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
                }
    
                // Simpan file baru
                $path = $request->file('company_qris')->store('qris_codes', 'public');
                \Illuminate\Support\Facades\Log::info('File stored at: ' . $path);
                
                // Update DB
                $setting = Setting::updateOrCreate(['key' => 'company_qris_path'], ['value' => $path]);
                \Illuminate\Support\Facades\Log::info('DB Updated: ', $setting->toArray());
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Upload failed: ' . $e->getMessage());
            }
        } else {
            \Illuminate\Support\Facades\Log::info('No file company_qris in request');
        }

        // 2. Loop data lain (kecuali file)
        foreach ($validated as $key => $value) {
            if ($key === 'company_qris') continue; // Skip file object

            // Konversi khusus untuk rate
            if ($key === 'commission_rate' && !is_null($value)) {
                $dbValue = $value / 100;
            } else {
                $dbValue = $value;
            }
            
            // Hanya update jika value tidak null (atau logic sesuai kebutuhan)
            if (!is_null($dbValue)) {
                Setting::updateOrCreate(['key' => $key], ['value' => $dbValue]);
            }
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

    // =========================================================
    // === MANAJEMEN ANTRIAN (QUEUE) ===
    // =========================================================

    /**
     * Ambil data antrian saat ini
     */
    public function adminGetQueue()
    {
        $queue = DriverQueue::with('driver.driverProfile')
            ->orderBy('sort_order', 'asc')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($q, $index) {
                return [
                    'user_id' => $q->user_id,
                    'name' => $q->driver->name ?? 'Unknown',
                    'line_number' => $q->driver->driverProfile->line_number ?? '-',
                    'sort_order' => $q->sort_order,
                    'joined_at' => $q->created_at,
                    'real_position' => $index + 1 // Urutan asli (1, 2, 3...)
                ];
            });

        return response()->json($queue);
    }

    /**
     * Keluarkan driver dari antrian (Kick)
     */
    public function adminRemoveFromQueue($userId)
    {
        DB::transaction(function () use ($userId) {
            // 1. Hapus dari tabel queue
            DriverQueue::where('user_id', $userId)->delete();

            // 2. Update status profil jadi offline
            DriverProfile::where('user_id', $userId)->update(['status' => 'offline']);
        });

        return response()->json(['message' => 'Driver berhasil dikeluarkan dari antrian.']);
    }

    /**
     * Ubah urutan antrian (Naik/Turun)
     * Logic: Menukar nilai sort_order dengan driver di sebelahnya
     */
    public function adminMoveQueue(Request $request)
    {
        $request->validate([
            'user_id' => 'required',
            'direction' => 'required|in:up,down'
        ]);

        $current = DriverQueue::where('user_id', $request->user_id)->firstOrFail();

        if ($request->direction === 'up') {
            // Cari driver di atasnya (sort_order lebih kecil)
            $neighbor = DriverQueue::where('sort_order', '<', $current->sort_order)
                ->orderBy('sort_order', 'desc')
                ->first();
        } else {
            // Cari driver di bawahnya (sort_order lebih besar)
            $neighbor = DriverQueue::where('sort_order', '>', $current->sort_order)
                ->orderBy('sort_order', 'asc')
                ->first();
        }

        if ($neighbor) {
            // Lakukan Swap (Tukar Nilai)
            $tempOrder = $current->sort_order;
            
            // Jika nilai sort_order kebetulan sama (konflik), kita buat selisih manual
            if ($tempOrder == $neighbor->sort_order) {
                $tempOrder = $request->direction === 'up' ? $neighbor->sort_order + 1 : $neighbor->sort_order - 1;
            }

            $current->update(['sort_order' => $neighbor->sort_order]);
            $neighbor->update(['sort_order' => $tempOrder]);
        }

        return response()->json(['message' => 'Urutan diperbarui.']);
    }

    /**
     * Update Line Number Driver
     */
    public function adminUpdateLineNumber(Request $request)
    {
        $request->validate([
            'user_id' => 'required',
            'line_number' => 'required|string|max:10'
        ]);

        DriverProfile::where('user_id', $request->user_id)
            ->update(['line_number' => $request->line_number]);

        return response()->json(['message' => 'Line Number diperbarui.']);
    }
    public function adminChangePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|current_password',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        $user = $request->user();
        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        return response()->json(['message' => 'Password berhasil diperbarui.']);
    }
}

