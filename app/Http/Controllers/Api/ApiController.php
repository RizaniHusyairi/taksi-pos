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
        $driversOnTrip = Booking::whereIn('status', ['Assigned', 'OnTrip'])
            ->distinct('driver_id')
            ->count('driver_id');

        // Total Aktif = Queue + OnTrip (Asumsi driver on trip otomatis keluar dari queue, jadi tidak double count)
        $activeDrivers = $driversInQueue + $driversOnTrip;
        $pendingWithdrawals = Withdrawals::where('status', 'Pending')->count();

        // 2. Siapkan Data Grafik Mingguan (7 hari terakhir)
        $weeklyChartData = Transaction::select(
                DB::raw($this->getSqlDate('created_at', 'date') . " as label"),
                DB::raw('SUM(amount) as total')
            )
            ->whereBetween('created_at', [now()->subDays(6), now()])
            ->groupBy('label')
            ->orderBy('label', 'asc')
            ->get();

        // 3. Siapkan Data Grafik Bulanan (12 bulan terakhir)
        $monthlyChartData = Transaction::select(
                DB::raw($this->getSqlDate('created_at', 'month') . " as label"),
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
        // Cegah hapus zona yang masih dipakai booking — kalau dihapus, zona pada
        // riwayat/laporan/struk jadi null (data rusak). Admin cukup mengedit zona.
        $usedCount = Booking::where('zone_id', $zone->id)->count();
        if ($usedCount > 0) {
            return response()->json([
                'message' => "Zona tidak bisa dihapus karena dipakai oleh {$usedCount} booking. Edit saja zonanya.",
            ], 422);
        }

        $zone->delete();
        return response()->json(['message' => 'Zone deleted successfully']);
    }

    // --- Manajemen Pengguna ---
    public function adminGetUsers() {
        // Paginasi agar payload tetap terbatas saat jumlah user bertumbuh.
        return response()->json(User::with('driverProfile')->orderBy('name')->paginate(20));
    }

    public function adminStoreUser(Request $request) {
        $validated = $request->validate([
            'name' => 'required|string',
            'username' => 'required|string|unique:users,username',
            'email' => 'nullable|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:cso,driver,admin',
            // validasi tambahan untuk supir
            'car_model' => 'nullable|string',
            'plate_number' => 'nullable|string',
        ]);

        $email = $validated['email'] ?? $validated['username'] . '@taksipos.test';

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $email,
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        if ($validated['role'] === 'driver') {
            // 1. Auto-generate Line Number
            // Because line_number might be stored as string, we need to convert it to integer to find the highest number
            $allLines = DriverProfile::pluck('line_number')->map(function ($line) {
                return (int) $line;
            });
            $maxLine = $allLines->max() ?? 0;
            $newLine = $maxLine + 1;

            $user->driverProfile()->create([
                'car_model' => $validated['car_model'] ?? '',
                'plate_number' => $validated['plate_number'] ?? '',
                'line_number' => $newLine
            ]);

            // Driver baru TIDAK langsung dimasukkan antrian — ia baru masuk saat
            // benar-benar tiba di area bandara (auto-join via updateLocation) atau
            // lewat rotasi harian. Mencegah "phantom availability" di daftar CSO.
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

        // Cegah skenario yang bisa mengunci akses admin saat menurunkan role admin:
        // (a) menurunkan role akun sendiri, (b) menurunkan admin terakhir.
        if ($user->role === 'admin' && $validated['role'] !== 'admin') {
            if ($user->id === Auth::id()) {
                return response()->json(['message' => 'Tidak bisa menurunkan role akun sendiri.'], 422);
            }
            if (User::where('role', 'admin')->count() <= 1) {
                return response()->json(['message' => 'Tidak bisa menurunkan admin terakhir.'], 422);
            }
        }

        $user->update([
            'name' => $validated['name'],
            'role' => $validated['role'],
        ]);

        if (!empty($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        if ($validated['role'] === 'driver') {
            // Pertahankan line_number lama; kalau belum ada (konversi dari role lain),
            // beri nomor berikutnya agar driver ikut sistem rotasi antrian
            // (DailyQueueRotation memakai whereNotNull('line_number')).
            $profile = $user->driverProfile; // null jika sebelumnya bukan driver
            $lineNumber = $profile?->line_number;
            if (empty($lineNumber)) {
                $maxLine = DriverProfile::pluck('line_number')->map(fn ($l) => (int) $l)->max() ?? 0;
                $lineNumber = $maxLine + 1;
            }
            // car_model & plate_number kolom NOT NULL: kalau tak dikirim, pertahankan
            // nilai lama (kalau ada) atau pakai string kosong.
            $user->driverProfile()->updateOrCreate([], [
                'car_model'    => $validated['car_model'] ?? $profile?->car_model ?? '',
                'plate_number' => $validated['plate_number'] ?? $profile?->plate_number ?? '',
                'line_number'  => $lineNumber,
            ]);
        } else {
            // Bukan driver lagi: keluarkan dari antrian + hapus profil driver.
            DriverQueue::where('user_id', $user->id)->delete();
            $user->driverProfile()->delete();
        }

        return response()->json($user->load('driverProfile'));
    }


    public function adminDestroyUser(User $user) {
        // Prevent deleting self
        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'Tidak bisa menghapus akun sendiri.'], 403);
        }

        // Cegah hapus user yang punya jejak booking/keuangan — kalau dihapus,
        // booking/transaksi/withdrawal jadi yatim (data & laporan rusak).
        // Untuk menghentikan akses, NONAKTIFKAN akunnya (toggle-status), jangan hapus.
        $hasBookings = Booking::where('driver_id', $user->id)->orWhere('cso_id', $user->id)->exists();
        $hasWithdrawals = Withdrawals::where('driver_id', $user->id)->exists();
        if ($hasBookings || $hasWithdrawals) {
            return response()->json([
                'message' => 'User ini punya riwayat booking/keuangan, jadi tidak bisa dihapus. Nonaktifkan saja akunnya.',
            ], 422);
        }

        DB::transaction(function() use ($user) {
            // Aman dihapus: belum punya jejak. Bersihkan antrian, token, & profil.
            DriverQueue::where('user_id', $user->id)->delete();
            $user->tokens()->delete();
            $user->driverProfile()->delete();
            $user->delete();
        });

        return response()->json(['message' => 'User berhasil dihapus.']);
    }

    /**
     * Aktif / non-aktifkan akun pengguna (toggle kolom `active`).
     * Akun non-aktif ditolak saat login (lihat ApiAuthController).
     */
    public function adminToggleUserStatus(User $user)
    {
        // Cegah admin menonaktifkan akunnya sendiri (bisa terkunci dari panel).
        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'Tidak bisa menonaktifkan akun sendiri.'], 403);
        }

        $user->active = !$user->active;
        $user->save();

        if (!$user->active) {
            // Saat dinonaktifkan: putuskan sesi mobile yang sedang berjalan.
            $user->tokens()->delete();

            // Jika driver: keluarkan dari antrian + blokir auto-join agar tidak
            // muncul di daftar CSO & tidak bisa dapat order.
            if ($user->role === 'driver') {
                DriverQueue::where('user_id', $user->id)->delete();
                DriverProfile::where('user_id', $user->id)->update([
                    'status' => 'offline',
                    'auto_join_blocked' => true,
                ]);
            }
        }

        return response()->json([
            'message' => $user->active ? 'Akun diaktifkan.' : 'Akun dinonaktifkan.',
            'active'  => (bool) $user->active,
        ]);
    }

    public function adminGetTransactions(Request $request)
    {
        $paginated = Transaction::filter($request)
            ->with(['booking.zoneTo', 'booking.driver', 'booking.cso'])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        // Ringkasan untuk SELURUH hasil filter (bukan hanya halaman ini).
        $base = Transaction::filter($request);
        $summary = [
            'count'       => (clone $base)->count(),
            'total'       => (float) (clone $base)->sum('amount'),
            'qris'        => (float) (clone $base)->where('method', 'QRIS')->sum('amount'),
            'cash_cso'    => (float) (clone $base)->where('method', 'CashCSO')->sum('amount'),
            'cash_driver' => (float) (clone $base)->where('method', 'CashDriver')->sum('amount'),
        ];

        return response()->json([
            'data'    => $paginated->items(),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'total'        => $paginated->total(),
            ],
            'summary' => $summary,
        ]);
    }

    // Ambil semua withdrawal request dengan data supirnya
    public function adminGetWithdrawals()
    {
        $withdrawals = Withdrawals::with('driver.driverProfile')
                                ->orderBy('requested_at', 'desc')
                                ->paginate(20);
        return response()->json($withdrawals);
    }

    // Setujui permintaan
    // [UBAH INI] Setujui Permintaan + Upload Bukti (Satu Langkah)
    public function adminApproveWithdrawal(Request $request, Withdrawals $withdrawal)
    {
        // Hanya pencairan yang masih 'Pending' yang boleh disetujui.
        // Tanpa guard ini, menyetujui pencairan yang sudah Approved/Rejected
        // bisa memproses ulang transaksi & mengacaukan saldo driver.
        if ($withdrawal->status !== 'Pending') {
            return response()->json([
                'message' => 'Pencairan ini sudah diproses sebelumnya (status: ' . $withdrawal->status . ').',
            ], 422);
        }

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
                $waToken = Setting::getValue('wa_token');
                
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

                    \App\Jobs\SendWhatsAppMessage::dispatch($driverPhone, $message, $waToken);
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
        // Hanya pencairan yang masih 'Pending' yang boleh ditolak.
        // KRITIS: tanpa guard ini, menolak pencairan yang sudah 'Approved'
        // akan mengembalikan transaksi yang sudah 'Paid' menjadi 'Unpaid',
        // sehingga uang yang sudah ditransfer MUNCUL LAGI di saldo driver
        // (driver bisa menariknya dua kali / double payout).
        if ($withdrawal->status !== 'Pending') {
            return response()->json([
                'message' => 'Pencairan ini sudah diproses sebelumnya (status: ' . $withdrawal->status . ').',
            ], 422);
        }

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
                        DB::raw($this->getSqlDate('created_at', 'week') . " as label"), // Format: YYYY-WW
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
                        DB::raw($this->getSqlDate('created_at', 'month') . " as label"), // Format: YYYY-MM
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
                        DB::raw($this->getSqlDate('created_at', 'date') . " as label"),
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
        $sortBy = $request->query('sort_by', 'trips'); // trips | revenue | rating
        $orderCol = match ($sortBy) {
            'revenue' => 'revenue',
            'rating'  => 'avg_rating',
            default   => 'trips',
        };

        $drivers = User::where('role', 'driver')
            ->withCount(['bookings as trips' => function ($query) {
                $query->where('status', 'Completed');
            }])
            ->withSum('transactions as revenue', 'amount')
            ->withAvg('ratings as avg_rating', 'stars')   // rata-rata bintang
            ->withCount('ratings as rating_count')        // jumlah penilaian
            ->orderByDesc($orderCol)
            ->get();

        return response()->json($drivers);
    }
    public function adminGetSettings()
    {
        // Mengambil semua settings
        $settings = Setting::all()->pluck('value', 'key');

        // JANGAN kirim nilai rahasia ke browser. Redaksi jadi string kosong, tapi
        // beri flag "<key>_is_set" supaya UI bisa menandai "sudah dikonfigurasi".
        foreach (['mail_password', 'wa_token'] as $sk) {
            $hasValue = isset($settings[$sk]) && $settings[$sk] !== null && $settings[$sk] !== '';
            $settings[$sk] = '';
            $settings[$sk . '_is_set'] = $hasValue;
        }

        // Format URL untuk gambar
        if (isset($settings['company_qris_path'])) {
            $settings['company_qris_url'] = asset('storage/' . $settings['company_qris_path']);
        }

        return response()->json($settings);
    }
    
    public function adminUpdateSettings(Request $request)
    {
        // Audit trail TANPA nilai — jangan pernah log $request->all() di sini:
        // payload memuat rahasia (mail_password, wa_token) yang kalau di-log akan
        // tersimpan plaintext di storage/logs. Cukup catat NAMA key yang diubah.
        \Illuminate\Support\Facades\Log::info('Admin memperbarui pengaturan', [
            'keys' => array_keys($request->except('company_qris')),
        ]);

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
            try {
                // Hapus file lama jika ada
                $oldPath = Setting::where('key', 'company_qris_path')->value('value');
                if ($oldPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($oldPath)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
                }
    
                // Simpan file baru
                $path = $request->file('company_qris')->store('qris_codes', 'public');
                
                // Update DB
                Setting::updateOrCreate(['key' => 'company_qris_path'], ['value' => $path]);
            } catch (\Exception $e) {
                // Jujur: jangan balas "berhasil" kalau upload QRIS gagal.
                \Illuminate\Support\Facades\Log::error('Gagal upload QRIS: ' . $e->getMessage());
                return response()->json([
                    'message' => 'Gagal mengunggah gambar QRIS. Pengaturan belum disimpan, silakan coba lagi.',
                ], 500);
            }
        }

        // 2. Loop data lain (kecuali file)
        foreach ($validated as $key => $value) {
            if ($key === 'company_qris') continue; // Skip file object

            // Field rahasia yang dibiarkan KOSONG = "jangan ubah" (pertahankan nilai
            // lama). Mencegah blank-submit menimpa rahasia jadi kosong — penting
            // karena GET sengaja meredaksi nilainya (lihat adminGetSettings).
            if (in_array($key, ['mail_password', 'wa_token'], true) && ($value === null || $value === '')) {
                continue;
            }

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

        // Invalidasi memo agar pembacaan setting berikutnya dapat nilai terbaru.
        Setting::forgetMemo();

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

            // 2. Update status profil jadi offline & BLOKIR AUTO JOIN
            DriverProfile::where('user_id', $userId)->update([
                'status' => 'offline',
                'auto_join_blocked' => true
            ]);
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
            DB::transaction(function() use ($current, $neighbor) {
                $temp = $current->sort_order;
                $current->update(['sort_order' => $neighbor->sort_order]);
                $neighbor->update(['sort_order' => $temp]);
            });
        }

        return response()->json(['message' => 'Urutan berhasil diubah.']);
    }

    /**
     * Ambil Log Aktivitas Driver
     */
    public function adminGetDriverActivity($userId)
    {
        $logs = \App\Models\DriverActivity::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json($logs);
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

        // line_number harus unik — kalau dipakai driver lain, rotasi antrian tabrakan.
        $taken = DriverProfile::where('line_number', $request->line_number)
            ->where('user_id', '!=', $request->user_id)
            ->exists();
        if ($taken) {
            return response()->json([
                'message' => "Nomor lambung {$request->line_number} sudah dipakai driver lain.",
            ], 422);
        }

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

    /**
     * Helper to return Date Format SQL compatible with SQLite and MySQL
     */
    private function getSqlDate($column, $type)
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            switch ($type) {
                // SQLite uses strftime. %Y-%m return YYYY-MM
                case 'month': return "strftime('%Y-%m', $column)";
                // %Y-%W return YYYY-WW (Week Number)
                case 'week':  return "strftime('%Y-%W', $column)";
                // date() returns YYYY-MM-DD
                case 'date':  return "date($column)";
                default:      return "date($column)";
            }
        } else {
            // MySQL / MariaDB
            switch ($type) {
                case 'month': return "DATE_FORMAT($column, '%Y-%m')";
                case 'week':  return "DATE_FORMAT($column, '%x-W%v')";
                case 'date':  return "DATE($column)";
                default:      return "DATE($column)";
            }
        }
    }
}

