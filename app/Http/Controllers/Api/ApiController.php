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
use App\Models\CsoDeposit;
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
        //
        // Total + pecahan metode bayar hari ini diambil dalam SATU query
        // beragregasi (pola yang sama dipakai dashboard CSO) — bukan empat
        // query terpisah untuk data yang sumbernya sama.
        $aggToday = Transaction::whereDate('created_at', today())
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->selectRaw("COALESCE(SUM(CASE WHEN method = 'CashCSO'    THEN amount ELSE 0 END), 0) as cash_cso")
            ->selectRaw("COALESCE(SUM(CASE WHEN method = 'CashDriver' THEN amount ELSE 0 END), 0) as cash_driver")
            ->selectRaw("COALESCE(SUM(CASE WHEN method = 'QRIS'       THEN amount ELSE 0 END), 0) as qris")
            ->first();

        // Pembanding kemarin, supaya angka hari ini punya konteks.
        $aggYesterday = Transaction::whereDate('created_at', today()->subDay())
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->first();

        $revenueToday = (float) $aggToday->total;
        $transactionsToday = (int) $aggToday->cnt;
        // A. Hitung driver di antrian (Online/Available)
        $driversInQueue = DriverQueue::count();

        // B. Hitung driver yang sedang OnTrip (Ada booking belum selesai)
        // Kita hitung jumlah User ID unik yang memiliki booking aktif
        $driversOnTrip = Booking::whereIn('status', ['Assigned', 'OnTrip'])
            ->distinct('driver_id')
            ->count('driver_id');

        // Total Aktif = Queue + OnTrip (Asumsi driver on trip otomatis keluar dari queue, jadi tidak double count)
        $activeDrivers = $driversInQueue + $driversOnTrip;

        $wdPending = Withdrawals::where('status', 'Pending')
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->first();
        $pendingWithdrawals = (int) $wdPending->cnt;

        // Uang koperasi yang sedang dipegang supir & belum disetor. Akumulatif,
        // bukan harian — inilah angka yang menentukan potongan saat pencairan.
        $driverDebt = (float) Transaction::where('method', 'CashDriver')
            ->where('payout_status', 'Unpaid')
            ->sum('amount');

        // 8 transaksi terakhir untuk panel Aktivitas Terbaru. Relasi di-eager
        // load (pola dashboard CSO) supaya tidak N+1 saat menampilkan nama.
        $recent = Transaction::with([
                'booking:id,cso_id,driver_id,zone_id,manual_destination',
                'booking.zoneTo:id,name',
                'booking.driver:id,name',
                'booking.cso:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (Transaction $t) => [
                'id'          => $t->id,
                'method'      => $t->method,
                'amount'      => (float) $t->amount,
                'created_at'  => optional($t->created_at)->toIso8601String(),
                'driver'      => $t->booking?->driver?->name,
                'cso'         => $t->booking?->cso?->name,
                'destination' => $t->booking?->zoneTo?->name
                    ?? $t->booking?->manual_destination
                    ?? '-',
            ]);

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

        // 4. Gabungkan semua data dalam satu respons JSON.
        //    Kunci lama dipertahankan apa adanya; yang baru ditambahkan di samping.
        return response()->json([
            'metrics' => [
                'revenue_today' => $revenueToday,
                'transactions_today' => $transactionsToday,
                'active_drivers' => $activeDrivers,
                'pending_withdrawals' => $pendingWithdrawals,

                // Pembanding & konteks tambahan
                'revenue_yesterday'          => (float) $aggYesterday->total,
                'transactions_yesterday'     => (int) $aggYesterday->cnt,
                'revenue_change_pct'         => $this->changePct($revenueToday, (float) $aggYesterday->total),
                'transactions_change_pct'    => $this->changePct($transactionsToday, (int) $aggYesterday->cnt),
                'pending_withdrawals_amount' => (float) $wdPending->total,
                'drivers_in_queue'           => $driversInQueue,
                'drivers_on_trip'            => $driversOnTrip,
            ],
            'payments' => [
                'cash_cso'    => (float) $aggToday->cash_cso,
                'cash_driver' => (float) $aggToday->cash_driver,
                'qris'        => (float) $aggToday->qris,
                'total'       => $revenueToday,
                'driver_debt' => $driverDebt,
            ],
            'recent' => $recent,
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

    /**
     * Persentase perubahan terhadap pembanding.
     *
     * Mengembalikan null (BUKAN 0 atau Infinity) bila pembandingnya nol —
     * "naik tak hingga persen dari nol" tidak bermakna, dan UI perlu tahu
     * bedanya agar bisa menampilkan tanda "—" alih-alih angka palsu.
     */
    private function changePct(float|int $sekarang, float|int $sebelumnya): ?float
    {
        if ($sebelumnya <= 0) {
            return null;
        }
        return round((($sekarang - $sebelumnya) / $sebelumnya) * 100, 1);
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
            'category' => 'nullable|in:dalam,luar',
            'description' => 'nullable|string|max:255',
        ]);
        $validated['category'] = $validated['category'] ?? 'dalam';
        $zone = Zone::create($validated);
        return response()->json($zone, 201);
    }

    public function adminUpdateZone(Request $request, Zone $zone) {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'category' => 'nullable|in:dalam,luar',
            'description' => 'nullable|string|max:255',
        ]);
        // Kategori tidak dikirim → jangan timpa jadi null (description boleh
        // null: itu cara mengosongkan keterangan).
        if (!isset($validated['category'])) {
            unset($validated['category']);
        }
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

    // =====================================================================
    // ===  SETORAN TUNAI CSO (verifikasi admin)                          ===
    // =====================================================================

    /** Daftar setoran CSO, terbaru dulu. Filter: status, cso_id, rentang tanggal. */
    public function adminGetCsoDeposits(Request $request)
    {
        $q = CsoDeposit::with(['cso:id,name,username', 'processedBy:id,name'])
            ->orderByDesc('submitted_at');

        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        if ($request->filled('cso_id')) {
            $q->where('cso_id', $request->query('cso_id'));
        }
        if ($request->filled('date_from')) {
            $q->whereDate('submitted_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $q->whereDate('submitted_at', '<=', $request->query('date_to'));
        }

        return response()->json($q->paginate(20));
    }

    /** Transaksi yang tercakup dalam satu setoran. */
    public function adminGetCsoDepositDetails(CsoDeposit $deposit)
    {
        $transactions = Transaction::with(['booking.zoneTo', 'booking.driver:id,name'])
            ->where('cso_deposit_id', $deposit->id)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'deposit'      => $deposit->load('cso:id,name,username'),
            'transactions' => $transactions,
        ]);
    }

    /** Admin menerima uangnya: setoran lunas, transaksinya ditandai Settled. */
    public function adminApproveCsoDeposit(Request $request, CsoDeposit $deposit)
    {
        if ($deposit->status !== 'Pending') {
            return response()->json([
                'message' => 'Setoran ini sudah diproses sebelumnya (status: ' . $deposit->status . ').',
            ], 422);
        }

        $request->validate([
            'proof_image' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            'admin_note'  => 'nullable|string|max:500',
        ]);

        $path = $request->hasFile('proof_image')
            ? $request->file('proof_image')->store('deposit_proofs', 'public')
            : null;

        DB::transaction(function () use ($deposit, $request, $path) {
            $deposit->update([
                'status'       => 'Approved',
                'admin_note'   => $request->input('admin_note'),
                'processed_at' => now(),
                'processed_by' => Auth::id(),
                // Bukti dari CSO dipertahankan bila admin tidak mengunggah apa pun.
                'proof_image'  => $path ?: $deposit->proof_image,
            ]);

            Transaction::where('cso_deposit_id', $deposit->id)
                ->update(['deposit_status' => 'Settled']);
        });

        $this->notifyCsoDeposit($deposit, true);

        return response()->json(['message' => 'Setoran disetujui dan transaksinya ditandai lunas.']);
    }

    /**
     * Admin menolak setoran (uang tidak cocok / belum diterima).
     * Transaksinya kembali jadi kewajiban CSO dan tanggalnya muncul lagi.
     */
    public function adminRejectCsoDeposit(Request $request, CsoDeposit $deposit)
    {
        // KRITIS — alasan yang sama dengan adminRejectWithdrawal: tanpa guard
        // ini, menolak setoran yang sudah 'Approved' akan mengubah transaksi
        // 'Settled' kembali jadi 'Unsettled', sehingga uang yang SUDAH diterima
        // admin muncul lagi sebagai tagihan ke CSO.
        if ($deposit->status !== 'Pending') {
            return response()->json([
                'message' => 'Setoran ini sudah diproses sebelumnya (status: ' . $deposit->status . ').',
            ], 422);
        }

        $validated = $request->validate([
            'admin_note' => 'required|string|max:500',
        ], [
            'admin_note.required' => 'Tulis alasan penolakan agar CSO tahu apa yang harus diperbaiki.',
        ]);

        DB::transaction(function () use ($deposit, $validated) {
            $deposit->update([
                'status'       => 'Rejected',
                'admin_note'   => $validated['admin_note'],
                'processed_at' => now(),
                'processed_by' => Auth::id(),
            ]);

            // Lepas ikatan agar tanggalnya kembali muncul di daftar "belum
            // disetor" dan bisa diajukan ulang.
            Transaction::where('cso_deposit_id', $deposit->id)
                ->update(['deposit_status' => 'Unsettled', 'cso_deposit_id' => null]);
        });

        $this->notifyCsoDeposit($deposit, false);

        return response()->json(['message' => 'Setoran ditolak dan tagihannya dikembalikan ke CSO.']);
    }

    /**
     * Kabari CSO lewat WhatsApp. Sengaja DI LUAR DB::transaction & dibungkus
     * try/catch: gagal kirim pesan tidak boleh membatalkan keputusan admin
     * (pola sama dengan adminApproveWithdrawal).
     */
    private function notifyCsoDeposit(CsoDeposit $deposit, bool $disetujui): void
    {
        try {
            $waToken = Setting::getValue('wa_token');
            $cso     = $deposit->cso;
            $phone   = $cso->phone_number ?? $cso->username;

            if (!$waToken || !$phone) {
                return;
            }

            $rp   = number_format((float) $deposit->amount, 0, ',', '.');
            $tgl  = optional($deposit->processed_at)->format('d M Y H:i') ?? now()->format('d M Y H:i');
            $hari = count($deposit->period_dates ?? []);

            $message = $disetujui
                ? "*SETORAN DITERIMA*\n\nHalo {$cso->name},\n\nSetoran tunai Anda sebesar *Rp {$rp}* ({$hari} tanggal) telah DIVERIFIKASI admin.\n\n📅 {$tgl}\n\nTerima kasih."
                : "*SETORAN DITOLAK*\n\nHalo {$cso->name},\n\nSetoran tunai Anda sebesar *Rp {$rp}* DITOLAK admin.\n\nAlasan: {$deposit->admin_note}\n\n📅 {$tgl}\n\nTagihan tanggal tersebut kembali muncul di aplikasi dan bisa diajukan ulang.";

            \App\Jobs\SendWhatsAppMessage::dispatch($phone, $message, $waToken);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gagal kirim WA setoran CSO: ' . $e->getMessage());
        }
    }

    // Anda juga bisa menambahkan metode untuk 'Mark as Paid' jika logikanya berbeda
    

    public function adminGetRevenueReport(Request $request)
    {
        // MODE BARU (dipakai halaman Laporan Pendapatan): ?month=YYYY-MM →
        // rincian pendapatan 1 bulan per metode bayar. Tanpa month → tetap
        // time-series lama (kompatibilitas).
        if ($request->filled('month')) {
            return $this->revenueByMethod($request->query('month'));
        }

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

    /**
     * Rincian pendapatan 1 bulan per metode bayar (CashCSO / CashDriver / QRIS)
     * + potongan komisi koperasi. Metode 'Transfer' tidak ada di sistem, jadi
     * tidak disertakan. Dipakai halaman Laporan Pendapatan admin.
     */
    private function revenueByMethod(?string $month)
    {
        try {
            $start = \Carbon\Carbon::createFromFormat('Y-m', (string) $month)->startOfMonth();
        } catch (\Throwable $e) {
            $start = now()->startOfMonth();
        }
        $end = (clone $start)->endOfMonth();

        $rows = Transaction::whereBetween('created_at', [$start, $end])
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        $cashCso    = (float) ($rows['CashCSO'] ?? 0);
        $cashDriver = (float) ($rows['CashDriver'] ?? 0);
        $qris       = (float) ($rows['QRIS'] ?? 0);
        $total      = $cashCso + $cashDriver + $qris;

        $rate = (float) Setting::getValue('commission_rate') ?: 0.2;
        $fee  = $total * $rate;

        return response()->json([
            'month'       => $start->format('Y-m'),
            'cash_cso'    => round($cashCso),
            'cash_driver' => round($cashDriver),
            'qris'        => round($qris),
            'total'       => round($total),
            'fee'         => round($fee),
            'fee_rate'    => $rate,
        ]);
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

    /**
     * Laporan Performa CSO — per bulan, satu baris per CSO.
     *
     * Beda dengan laporan supir yang akumulatif sejak awal: CSO bekerja per
     * shift/periode, jadi angkanya disaring ke satu bulan agar bisa
     * dibandingkan antar-periode.
     *
     * Definisi status (alur: Assigned → Paid/CashDriver → Completed):
     * - selesai    = 'Completed' (sama dengan definisi di laporan supir)
     * - dibatalkan = 'Cancelled'
     * - sisanya masih berjalan → ikut Total, tapi tidak di dua kolom itu.
     *   Karena itu selesai + dibatalkan SENGAJA tidak selalu sama dengan total.
     */
    public function adminGetCsoPerformanceReport(Request $request)
    {
        $validated = $request->validate([
            'month'   => 'nullable|date_format:Y-m',
            'sort_by' => 'nullable|in:orders,revenue,cancelled',
        ]);

        $month = $validated['month'] ?? now()->format('Y-m');
        $start = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end   = (clone $start)->endOfMonth();
        $range = [$start, $end];

        $sortBy = $validated['sort_by'] ?? 'orders';
        $orderCol = match ($sortBy) {
            'revenue'   => 'revenue',
            'cancelled' => 'cancelled',
            default     => 'orders',
        };

        $csos = User::where('role', 'cso')
            ->withCount([
                // Kolom created_at diberi prefix nama tabel: withCount menempel
                // pada subquery yang juga menyentuh 'users', jadi tanpa prefix
                // nama kolomnya ambigu.
                'csoBookings as orders' => fn ($q) => $q->whereBetween('bookings.created_at', $range),
                'csoBookings as completed' => fn ($q) => $q
                    ->whereBetween('bookings.created_at', $range)
                    ->where('status', 'Completed'),
                'csoBookings as cancelled' => fn ($q) => $q
                    ->whereBetween('bookings.created_at', $range)
                    ->where('status', 'Cancelled'),
            ])
            ->withSum([
                'csoTransactions as revenue' => fn ($q) => $q->whereBetween('transactions.created_at', $range),
            ], 'amount')
            // SENGAJA tanpa batas bulan: justru gunanya menandai CSO yang sudah
            // lama tidak membuat pesanan sama sekali.
            ->withMax('csoBookings as last_order_at', 'bookings.created_at')
            ->orderByDesc($orderCol)
            ->get()
            ->map(function (User $u) {
                $orders  = (int) $u->orders;
                $revenue = (float) ($u->revenue ?? 0);

                return [
                    'id'              => $u->id,
                    'name'            => $u->name,
                    'username'        => $u->username,
                    'orders'          => $orders,
                    'completed'       => (int) $u->completed,
                    'cancelled'       => (int) $u->cancelled,
                    'revenue'         => $revenue,
                    // Dijaga dari pembagian nol: CSO tanpa pesanan bulan itu
                    // tetap muncul dengan angka 0, bukan NaN/Infinity.
                    'avg_order_value' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
                    'cancel_rate'     => $orders > 0 ? round($u->cancelled / $orders * 100, 1) : 0.0,
                    'last_order_at'   => optional($u->last_order_at ? \Carbon\Carbon::parse($u->last_order_at) : null)
                        ->toIso8601String(),
                ];
            });

        return response()->json([
            'month'   => $month,
            'sort_by' => $sortBy,
            'summary' => [
                'cso_count' => $csos->count(),
                'orders'    => (int) $csos->sum('orders'),
                'completed' => (int) $csos->sum('completed'),
                'cancelled' => (int) $csos->sum('cancelled'),
                'revenue'   => (float) $csos->sum('revenue'),
            ],
            'csos' => $csos->values(),
        ]);
    }

    // =====================================================================
    // ===  SETORAN TUNAI SUPIR (verifikasi admin)                        ===
    // =====================================================================
    //
    // Kembar dengan blok setoran CSO di atas — sengaja, supaya admin tidak
    // perlu mempelajari dua alur verifikasi yang berbeda untuk hal yang sama.
    // Bedanya cuma buku besar yang disentuh: setoran CSO memakai
    // `deposit_status`, setoran supir memakai `payout_status` (lihat migrasi
    // 2026_08_24_000005 untuk alasannya).

    /** Daftar setoran supir, terbaru dulu. Filter: status, driver_id, tanggal. */
    public function adminGetDriverDeposits(Request $request)
    {
        $q = \App\Models\DriverDeposit::with(['driver:id,name,username', 'processedBy:id,name'])
            ->orderByDesc('submitted_at');

        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        if ($request->filled('driver_id')) {
            $q->where('driver_id', $request->query('driver_id'));
        }
        if ($request->filled('date_from')) {
            $q->whereDate('submitted_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $q->whereDate('submitted_at', '<=', $request->query('date_to'));
        }

        return response()->json($q->paginate(20));
    }

    /** Transaksi yang utangnya tercakup dalam satu setoran supir. */
    public function adminGetDriverDepositDetails(\App\Models\DriverDeposit $deposit)
    {
        $transactions = Transaction::with(['booking.zoneTo', 'booking.cso:id,name'])
            ->where('driver_deposit_id', $deposit->id)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'deposit'      => $deposit->load('driver:id,name,username'),
            'transactions' => $transactions,
        ]);
    }

    /** Admin menerima uangnya: utang lunas, transaksinya ditandai Paid. */
    public function adminApproveDriverDeposit(Request $request, \App\Models\DriverDeposit $deposit)
    {
        if ($deposit->status !== 'Pending') {
            return response()->json([
                'message' => 'Setoran ini sudah diproses sebelumnya (status: ' . $deposit->status . ').',
            ], 422);
        }

        $request->validate([
            'proof_image' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            'admin_note'  => 'nullable|string|max:500',
        ]);

        $path = $request->hasFile('proof_image')
            ? $request->file('proof_image')->store('deposit_proofs', 'public')
            : null;

        DB::transaction(function () use ($deposit, $request, $path) {
            $deposit->update([
                'status'       => 'Approved',
                'admin_note'   => $request->input('admin_note'),
                'processed_at' => now(),
                'processed_by' => Auth::id(),
                'proof_image'  => $path ?: $deposit->proof_image,
            ]);

            Transaction::where('driver_deposit_id', $deposit->id)
                ->update(['payout_status' => 'Paid']);
        });

        $this->notifyDriverDeposit($deposit, true);

        return response()->json(['message' => 'Setoran disetujui dan utangnya ditandai lunas.']);
    }

    /**
     * Admin menolak setoran (uang tidak cocok / belum diterima).
     * Utangnya kembali menjadi kewajiban supir dan tanggalnya muncul lagi.
     */
    public function adminRejectDriverDeposit(Request $request, \App\Models\DriverDeposit $deposit)
    {
        // KRITIS — alasan yang sama dengan adminRejectWithdrawal &
        // adminRejectCsoDeposit: tanpa guard ini, menolak setoran yang sudah
        // 'Approved' akan mengubah transaksi 'Paid' kembali jadi 'Unpaid',
        // sehingga utang yang SUDAH dibayar muncul lagi sebagai tagihan.
        if ($deposit->status !== 'Pending') {
            return response()->json([
                'message' => 'Setoran ini sudah diproses sebelumnya (status: ' . $deposit->status . ').',
            ], 422);
        }

        $validated = $request->validate([
            'admin_note' => 'required|string|max:500',
        ], [
            'admin_note.required' => 'Tulis alasan penolakan agar supir tahu apa yang harus diperbaiki.',
        ]);

        DB::transaction(function () use ($deposit, $validated) {
            $deposit->update([
                'status'       => 'Rejected',
                'admin_note'   => $validated['admin_note'],
                'processed_at' => now(),
                'processed_by' => Auth::id(),
            ]);

            // Lepas ikatan agar tanggalnya kembali muncul di daftar "belum
            // disetor" dan bisa diajukan ulang.
            Transaction::where('driver_deposit_id', $deposit->id)
                ->update(['payout_status' => 'Unpaid', 'driver_deposit_id' => null]);
        });

        $this->notifyDriverDeposit($deposit, false);

        return response()->json(['message' => 'Setoran ditolak dan utangnya dikembalikan ke supir.']);
    }

    /**
     * Kabari supir lewat WhatsApp. Di LUAR DB::transaction & dibungkus
     * try/catch: gagal kirim pesan tidak boleh membatalkan keputusan admin.
     */
    private function notifyDriverDeposit(\App\Models\DriverDeposit $deposit, bool $disetujui): void
    {
        try {
            $waToken = Setting::getValue('wa_token');
            $driver  = $deposit->driver;
            $phone   = $driver->phone_number ?? $driver->username;

            if (!$waToken || !$phone) {
                return;
            }

            $rp   = number_format((float) $deposit->amount, 0, ',', '.');
            $tgl  = optional($deposit->processed_at)->format('d M Y H:i') ?? now()->format('d M Y H:i');
            $hari = count($deposit->period_dates ?? []);

            $message = $disetujui
                ? "*SETORAN DITERIMA*\n\nHalo {$driver->name},\n\nSetoran tunai Anda sebesar *Rp {$rp}* ({$hari} tanggal) telah DIVERIFIKASI admin. Utang setoran Anda berkurang.\n\n📅 {$tgl}\n\nTerima kasih."
                : "*SETORAN DITOLAK*\n\nHalo {$driver->name},\n\nSetoran tunai Anda sebesar *Rp {$rp}* DITOLAK admin.\n\nAlasan: {$deposit->admin_note}\n\n📅 {$tgl}\n\nTagihan tanggal tersebut kembali muncul di aplikasi dan bisa diajukan ulang.";

            \App\Jobs\SendWhatsAppMessage::dispatch($phone, $message, $waToken);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gagal kirim WA setoran supir: ' . $e->getMessage());
        }
    }

    // =====================================================================
    // ===  SENGKETA METODE PEMBAYARAN (keputusan admin)                  ===
    // =====================================================================

    /** Daftar sanggahan supir. Default: yang belum diputus. */
    public function adminGetMethodDisputes(Request $request)
    {
        $status = $request->query('status', 'Open');

        $q = Transaction::with([
                'booking.driver:id,name',
                'booking.cso:id,name',
                'booking.zoneTo:id,name',
            ])
            ->whereNotNull('method_dispute_status')
            ->orderByDesc('method_disputed_at');

        if ($status !== '') {
            $q->where('method_dispute_status', $status);
        }

        return response()->json($q->paginate(20));
    }

    /**
     * Admin membenarkan sanggahan: uangnya ternyata diterima CSO.
     *
     * Ini MEMINDAHKAN kewajiban, jadi bukan sekadar mengubah label:
     *  - metode 'CashDriver' -> 'CashCSO'. Otomatis utang komisi lepas dari
     *    supir dan berubah jadi hak pemasukannya (lihat hitungSaldo()).
     *  - `deposit_status` dipaksa 'Unsettled' secara EKSPLISIT. Tidak boleh
     *    mengandalkan nilai bawaan: transaksi lama sempat di-backfill jadi
     *    'Settled' (migrasi 2026_08_24_000002), sehingga tanpa baris ini uang
     *    itu lenyap — tidak ditagih ke supir, tidak juga ke CSO.
     *  - `original_method` disimpan agar pola CSO yang berulang kali
     *    salah-label tetap bisa ditelusuri setelah dikoreksi.
     *
     * Koreksi HANYA ke 'CashCSO'. Mengizinkan 'QRIS' terdengar lebih fleksibel
     * tapi tidak koheren: transaksi QRIS wajib punya foto bukti transfer, dan
     * di sini tidak ada satu pun bukti yang diunggah.
     */
    public function adminUpholdMethodDispute(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'admin_note' => 'nullable|string|max:500',
        ]);

        if ($transaction->method_dispute_status !== 'Open') {
            return response()->json([
                'message' => 'Sanggahan ini sudah diputus sebelumnya (status: '
                    . ($transaction->method_dispute_status ?? 'tidak ada') . ').',
            ], 422);
        }

        // Diperiksa ULANG di sini, bukan cuma saat sanggahan dibuat: di antara
        // kedua momen itu supir bisa saja mengajukan pencairan atau setoran,
        // dan mengoreksi metode transaksi yang bukunya sudah tutup akan
        // mengubah angka yang sudah dibayarkan.
        if ($transaction->payout_status !== 'Unpaid') {
            return response()->json([
                'message' => 'Transaksi ini sudah masuk pencairan/setoran (status: '
                    . $transaction->payout_status . '). Koreksi harus dilakukan manual.',
            ], 422);
        }

        DB::transaction(function () use ($transaction, $validated) {
            $transaction->update([
                'original_method'       => $transaction->method,
                'method'                => 'CashCSO',
                'deposit_status'        => 'Unsettled',
                'cso_deposit_id'        => null,
                'method_dispute_status' => 'Upheld',
                'method_admin_note'     => $validated['admin_note'] ?? null,
                'method_resolved_at'    => now(),
                'method_resolved_by'    => Auth::id(),
            ]);
        });

        $this->notifyMethodDispute($transaction->fresh(), true);

        return response()->json([
            'message' => 'Sanggahan dikabulkan. Order ini kini tercatat Tunai ke Kasir '
                . 'dan menjadi kewajiban setoran CSO.',
        ]);
    }

    /** Admin menolak sanggahan: uangnya memang diterima supir. */
    public function adminRejectMethodDispute(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'admin_note' => 'required|string|max:500',
        ], [
            'admin_note.required' => 'Tulis alasan penolakan agar supir tahu dasar keputusannya.',
        ]);

        if ($transaction->method_dispute_status !== 'Open') {
            return response()->json([
                'message' => 'Sanggahan ini sudah diputus sebelumnya (status: '
                    . ($transaction->method_dispute_status ?? 'tidak ada') . ').',
            ], 422);
        }

        $transaction->update([
            'method_dispute_status' => 'Rejected',
            'method_admin_note'     => $validated['admin_note'],
            'method_resolved_at'    => now(),
            'method_resolved_by'    => Auth::id(),
        ]);

        $this->notifyMethodDispute($transaction->fresh(), false);

        return response()->json(['message' => 'Sanggahan ditolak. Utang supir tetap berlaku.']);
    }

    /**
     * Kabari SUPIR selalu, dan CSO hanya bila sanggahan dikabulkan — karena
     * saat itulah muncul kewajiban baru di pundaknya. Di luar DB::transaction
     * & dibungkus try/catch: gagal kirim pesan tidak boleh membatalkan
     * keputusan admin (pola sama dengan notifyCsoDeposit).
     */
    private function notifyMethodDispute(Transaction $transaction, bool $dikabulkan): void
    {
        try {
            $waToken = Setting::getValue('wa_token');
            if (!$waToken) {
                return;
            }

            $booking = $transaction->booking;
            $driver  = $booking?->driver;
            $cso     = $booking?->cso;
            $rp      = number_format((float) $transaction->amount, 0, ',', '.');
            $tgl     = optional($transaction->method_resolved_at)->format('d M Y H:i')
                ?? now()->format('d M Y H:i');

            $driverPhone = $driver?->phone_number ?? $driver?->username;
            if ($driverPhone) {
                $pesan = $dikabulkan
                    ? "*SANGGAHAN DIKABULKAN*\n\nHalo {$driver->name},\n\nOrder #{$transaction->booking_id} (Rp {$rp}) dikoreksi menjadi *Tunai ke Kasir*. Utang komisi atas order ini dihapus dari dompet Anda.\n\n📅 {$tgl}"
                    : "*SANGGAHAN DITOLAK*\n\nHalo {$driver->name},\n\nSanggahan Anda atas order #{$transaction->booking_id} (Rp {$rp}) ditolak admin.\n\nAlasan: {$transaction->method_admin_note}\n\n📅 {$tgl}";
                \App\Jobs\SendWhatsAppMessage::dispatch($driverPhone, $pesan, $waToken);
            }

            if ($dikabulkan && $cso) {
                $csoPhone = $cso->phone_number ?? $cso->username;
                if ($csoPhone) {
                    \App\Jobs\SendWhatsAppMessage::dispatch(
                        $csoPhone,
                        "*KOREKSI METODE PEMBAYARAN*\n\nHalo {$cso->name},\n\nOrder #{$transaction->booking_id} (Rp {$rp}) dikoreksi admin dari Tunai ke Supir menjadi *Tunai ke Kasir*.\n\nUang ini kini tercatat sebagai kewajiban setoran Anda dan muncul di menu Setoran.\n\n📅 {$tgl}",
                        $waToken
                    );
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gagal kirim WA sengketa metode: ' . $e->getMessage());
        }
    }

    // =====================================================================
    // ===  LAPORAN SINYAL KECURANGAN                                     ===
    // =====================================================================

    /**
     * Tiga daftar yang menuntut PERTANYAAN, bukan tiga vonis.
     *
     * Sistem ini punya beberapa titik yang sepenuhnya bergantung pada kejujuran
     * orang: CSO memilih sendiri supir mana yang dapat order, mengetik sendiri
     * nomor penumpang, dan supir memutuskan sendiri apakah trip di luar antrian
     * dilaporkan. Tidak satu pun bisa dikunci lewat kode tanpa melumpuhkan
     * operasional — mobil memang bisa mogok, penumpang memang bisa menolak
     * supir, dan supir memang boleh pulang. Yang bisa dilakukan kode adalah
     * MEMBUAT POLANYA TERLIHAT, lalu menyerahkan keputusannya ke pengurus.
     *
     * Karena itu setiap angka di sini dikembalikan apa adanya beserta
     * pembandingnya (total order, jumlah pemakaian, durasi) — bukan sebagai
     * skor "kecurigaan" yang terkesan objektif padahal cuma tebakan berbaju
     * matematika.
     */
    public function adminGetFraudSignals(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to'   => 'nullable|date_format:Y-m-d',
            // Berapa kali satu nomor HP dipakai sebelum layak ditanyakan.
            'phone_min' => 'nullable|integer|min:2|max:100',
        ]);

        $from = \Carbon\Carbon::parse($validated['date_from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to   = \Carbon\Carbon::parse($validated['date_to'] ?? now()->toDateString())->endOfDay();
        $range = [$from, $to];
        $phoneMin = (int) ($validated['phone_min'] ?? 3);

        return response()->json([
            'period' => [
                'from' => $from->toDateString(),
                'to'   => $to->toDateString(),
            ],
            'queue_overrides'  => $this->sinyalOverrideAntrian($range),
            'repeated_phones'  => $this->sinyalNomorBerulang($range, $phoneMin),
            'unreported_trips' => $this->sinyalKeluarAreaTanpaOrder($range),
            'phone_min'        => $phoneMin,
        ]);
    }

    /**
     * Seberapa sering tiap CSO melewati giliran antrian.
     *
     * Override itu SAH — ada mobil mogok, penumpang menolak, supir tak muncul.
     * Yang tidak wajar adalah pola: satu CSO yang melewati giliran jauh lebih
     * sering daripada rekannya, atau yang selalu melewati orang yang sama demi
     * supir yang sama. Karena itu yang ditampilkan rasio (bukan jumlah mentah)
     * plus pasangan supir yang paling sering diuntungkan/dirugikan.
     */
    private function sinyalOverrideAntrian(array $range): array
    {
        $csos = User::where('role', 'cso')
            ->withCount([
                'csoBookings as total_orders' => fn ($q) => $q->whereBetween('bookings.created_at', $range),
                'csoBookings as overrides' => fn ($q) => $q
                    ->whereBetween('bookings.created_at', $range)
                    ->where('queue_override', true),
            ])
            ->get()
            ->filter(fn ($u) => $u->total_orders > 0)
            ->map(function (User $u) use ($range) {
                $total = (int) $u->total_orders;
                $over  = (int) $u->overrides;

                // Pasangan yang paling sering berulang: siapa yang didahulukan,
                // dan siapa yang dilewati. Hanya diambil bila ADA override,
                // supaya CSO bersih tidak ikut membebani query.
                $pasangan = null;
                if ($over > 0) {
                    $pasangan = Booking::query()
                        ->where('cso_id', $u->id)
                        ->where('queue_override', true)
                        ->whereBetween('created_at', $range)
                        ->whereNotNull('skipped_driver_id')
                        ->selectRaw('driver_id, skipped_driver_id, COUNT(*) as jml')
                        ->groupBy('driver_id', 'skipped_driver_id')
                        ->orderByDesc('jml')
                        ->first();
                }

                return [
                    'id'            => $u->id,
                    'name'          => $u->name,
                    'total_orders'  => $total,
                    'overrides'     => $over,
                    'override_rate' => round($over / $total * 100, 1),
                    'top_pair'      => $pasangan ? [
                        'favored_driver' => optional(User::find($pasangan->driver_id))->name,
                        'skipped_driver' => optional(User::find($pasangan->skipped_driver_id))->name,
                        'count'          => (int) $pasangan->jml,
                    ] : null,
                ];
            })
            ->sortByDesc('override_rate')
            ->values();

        return [
            // Rata-rata seluruh CSO — TANPA ini angka 12% tidak berarti apa-apa.
            // Yang dicari pengurus adalah siapa yang menyimpang dari rekannya,
            // bukan siapa yang melewati ambang yang kita karang sendiri.
            'average_rate' => $csos->count() > 0
                ? round($csos->sum('overrides') / max($csos->sum('total_orders'), 1) * 100, 1)
                : 0.0,
            'csos' => $csos->all(),
        ];
    }

    /**
     * Nomor HP penumpang yang dipakai berulang kali.
     *
     * Nomor penumpang adalah SATU-SATUNYA jalur verifikasi independen yang
     * dimiliki sistem: dari sanalah penumpang menerima struk berisi tarif yang
     * sebenarnya tercatat. CSO yang mengetik nomornya sendiri memutus jalur itu
     * — dan itulah yang membuat "pilih zona murah, tagih tarif jauh" tidak
     * pernah ketahuan.
     *
     * Berulang tidak selalu curang: pelanggan tetap dan penjemputan rombongan
     * itu nyata. Karena itu setiap baris membawa daftar CSO yang memakainya —
     * satu nomor yang dipakai banyak CSO wajar, satu nomor yang selalu dipakai
     * CSO yang sama jauh lebih layak ditanyakan.
     */
    private function sinyalNomorBerulang(array $range, int $minimal): array
    {
        $nomors = Booking::query()
            ->whereBetween('created_at', $range)
            ->whereNotNull('passenger_phone')
            ->where('passenger_phone', '!=', '')
            ->selectRaw('passenger_phone, COUNT(*) as jml, COUNT(DISTINCT cso_id) as jml_cso')
            ->groupBy('passenger_phone')
            ->havingRaw('COUNT(*) >= ?', [$minimal])
            ->orderByDesc('jml')
            ->limit(50)
            ->get();

        return $nomors->map(function ($n) use ($range) {
            $csoNames = Booking::query()
                ->where('passenger_phone', $n->passenger_phone)
                ->whereBetween('created_at', $range)
                ->with('cso:id,name')
                ->get()
                ->pluck('cso.name')
                ->filter()
                ->unique()
                ->values()
                ->all();

            return [
                'phone'     => $n->passenger_phone,
                'count'     => (int) $n->jml,
                'cso_count' => (int) $n->jml_cso,
                'csos'      => $csoNames,
            ];
        })->all();
    }

    /**
     * Supir yang keluar area bandara lalu kembali, TANPA order tercatat.
     *
     * Inilah bentuk terukur dari celah "fee trip mandiri berbasis kejujuran":
     * supir yang dapat penumpang sendiri seharusnya keluar antrian dengan
     * alasan `self` (kena fee flat), tapi bisa memilih `other` alias
     * "istirahat" yang gratis. Server tidak bisa membedakan keduanya saat
     * kejadian — tapi JEJAKNYA berbeda, dan jejak itu sudah lama terekam:
     * pasangan AIRPORT_EXIT → AIRPORT_ENTER di `driver_activities`.
     *
     * Yang dilaporkan hanya kepergian yang MASUK AKAL sebagai satu trip:
     * terlalu singkat = cuma beli makan atau menyeberang batas geofence;
     * terlalu lama = pulang, bukan mengantar. Trip yang jujur dilaporkan
     * (termasuk lewat tombol "Dapat Penumpang Sendiri") otomatis tidak muncul,
     * karena booking-nya menutupi rentang waktu tersebut.
     *
     * Ini SINYAL, bukan bukti: supir bisa saja mengantar keluarganya sendiri.
     */
    private function sinyalKeluarAreaTanpaOrder(array $range): array
    {
        // Rentang durasi yang pantas dicurigai sebagai satu trip mengantar.
        $minMenit = 15;
        $maxMenit = 240;

        $aktivitas = \App\Models\DriverActivity::query()
            ->whereIn('activity_type', ['AIRPORT_EXIT', 'AIRPORT_ENTER'])
            ->whereBetween('created_at', $range)
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->get(['user_id', 'activity_type', 'created_at']);

        // Pasangkan tiap EXIT dengan ENTER berikutnya milik supir yang sama.
        $kepergian = [];
        foreach ($aktivitas->groupBy('user_id') as $userId => $baris) {
            $keluar = null;
            foreach ($baris as $a) {
                if ($a->activity_type === 'AIRPORT_EXIT') {
                    $keluar = $a->created_at;
                    continue;
                }
                // ENTER tanpa EXIT sebelumnya = supir baru datang hari itu.
                if ($keluar === null) {
                    continue;
                }
                $menit = $keluar->diffInMinutes($a->created_at);
                if ($menit >= $minMenit && $menit <= $maxMenit) {
                    $kepergian[] = [
                        'user_id' => (int) $userId,
                        'keluar'  => $keluar,
                        'kembali' => $a->created_at,
                        'menit'   => (int) $menit,
                    ];
                }
                $keluar = null;
            }
        }

        if (empty($kepergian)) {
            return [];
        }

        // Order yang menutupi tiap kepergian. Satu query untuk semua supir,
        // bukan satu query per kepergian — daftar ini bisa panjang.
        $userIds = array_unique(array_column($kepergian, 'user_id'));
        $bookings = Booking::query()
            ->whereIn('driver_id', $userIds)
            ->where('status', '!=', 'Cancelled')
            // Longgar di kedua ujung: order dibuat sebelum supir bergerak, dan
            // diselesaikan setelah ia kembali.
            ->where('created_at', '<=', $range[1]->copy()->addHours(6))
            ->where('created_at', '>=', $range[0]->copy()->subHours(6))
            ->get(['id', 'driver_id', 'created_at', 'updated_at'])
            ->groupBy('driver_id');

        $hasil = [];
        foreach ($kepergian as $k) {
            $milik = $bookings[$k['user_id']] ?? collect();

            // "Tertutupi" = ada order yang dibuat sebelum/saat supir keluar dan
            // baru berubah status setelah ia bergerak. Sengaja longgar: lebih
            // baik melewatkan satu kasus daripada menuduh supir yang jujur.
            $tertutupi = $milik->contains(function ($b) use ($k) {
                return $b->created_at->lte($k['kembali'])
                    && $b->updated_at->gte($k['keluar']);
            });

            if (!$tertutupi) {
                $hasil[] = $k;
            }
        }

        // Ringkas per supir: yang dicari pola berulang, bukan daftar mentah.
        $namaSupir = User::whereIn('id', array_unique(array_column($hasil, 'user_id')))
            ->pluck('name', 'id');

        $perSupir = [];
        foreach ($hasil as $h) {
            $id = $h['user_id'];
            $perSupir[$id] ??= [
                'id'            => $id,
                'name'          => $namaSupir[$id] ?? 'Supir #' . $id,
                'trips'         => 0,
                'total_minutes' => 0,
                'last_at'       => null,
            ];
            $perSupir[$id]['trips']++;
            $perSupir[$id]['total_minutes'] += $h['menit'];
            $perSupir[$id]['last_at'] = $h['kembali']->toIso8601String();
        }

        usort($perSupir, fn ($a, $b) => $b['trips'] <=> $a['trips']);

        return array_values($perSupir);
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

        // Radius area bandara — selalu kirim nilai efektif (setting || config default)
        // agar field di UI tidak pernah kosong.
        $settings['airport_radius_km'] = (float) $settings->get(
            'airport_radius_km',
            config('taksi.driver_queue.radius_km')
        );

        // Titik pusat area bandara — sama, kirim nilai efektif. Lewat model agar
        // aturan validasi/fallback-nya persis sama dengan yang dipakai geofence.
        $settings['airport_latitude']  = \App\Models\Setting::airportLatitude();
        $settings['airport_longitude'] = \App\Models\Setting::airportLongitude();

        // Tenggang "di luar area" (menit) — nilai efektif lewat model supaya aturan
        // clamp/fallback-nya persis sama dengan yang dipakai auto-keluar antrian.
        $settings['out_of_area_grace_minutes'] = Setting::outOfAreaGraceMinutes();

        // WhatsApp Gateway — kirim nilai efektif (default sesuai dokumentasi gateway).
        // Yang ditampilkan adalah BASE URL yang sudah dinormalkan, jadi nilai lama
        // berbentuk URL kirim lengkap pun tampil konsisten sebagai base.
        $settings['wa_endpoint'] = WhatsAppService::baseUrl();
        // Device ID opsional — kirim apa adanya ('' bila belum diatur), JANGAN
        // paksa jadi 1 (memaksa device bisa memicu 403 dari gateway).
        // Device ID tidak lagi diisi admin — nilai bawaannya 0 = pakai device
        // bawaan API Key. Tetap dikirim agar konsumen API lain tidak kehilangan
        // kuncinya secara mendadak.
        $settings['wa_device_id'] = (string) $settings->get(
            'wa_device_id',
            (string) WhatsAppService::DEFAULT_DEVICE_ID
        );

        // Jam operasi pelacakan lokasi — kirim nilai efektif (setting || config)
        // agar field di UI tidak pernah kosong.
        $oh = \App\Models\Setting::operatingHours();
        $settings['operating_start'] = $oh['start'];
        $settings['operating_end']   = $oh['end'];

        // Batas utang supir — nilai efektif lewat model (0 = pembatasan mati),
        // supaya field di UI tidak pernah kosong dan artinya tidak ambigu.
        $settings['max_driver_debt'] = Setting::maxDriverDebt();

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
            'airport_radius_km' => 'nullable|numeric|min:0.1|max:50',
            'airport_latitude'  => 'nullable|numeric|between:-90,90',
            'airport_longitude' => 'nullable|numeric|between:-180,180',
            // 0 = auto-keluar antrian dinonaktifkan (lihat Setting::outOfAreaGraceMinutes).
            'out_of_area_grace_minutes' => 'nullable|integer|min:0|max:720',
            // Batas utang supir sebelum dilarang masuk antrian.
            // 0 = pembatasan dimatikan (lihat Setting::maxDriverDebt).
            'max_driver_debt'   => 'nullable|integer|min:0|max:100000000',
            'wa_endpoint'       => 'nullable|url|max:255',
            'wa_device_id'      => 'nullable|integer|min:0', // 0 = device bawaan API Key
            'operating_start'   => 'nullable|date_format:H:i',
            'operating_end'     => 'nullable|date_format:H:i',
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

        // Device ID WA: nilai kosong/'0' sah dan berarti "pakai device bawaan
        // API Key". Middleware mengubah '' → null sehingga loop di atas
        // melewatinya (null di-skip), jadi disimpan eksplisit di sini —
        // kosong dinormalkan jadi 0 supaya nilainya selalu terdefinisi.
        if ($request->has('wa_device_id')) {
            $device = trim((string) $request->input('wa_device_id'));
            Setting::updateOrCreate(
                ['key' => 'wa_device_id'],
                ['value' => $device === '' ? (string) WhatsAppService::DEFAULT_DEVICE_ID : $device]
            );
        }

        // Invalidasi memo agar pembacaan setting berikutnya dapat nilai terbaru.
        Setting::forgetMemo();

        return response()->json(['message' => 'Pengaturan berhasil disimpan.']);
    }

    /**
     * Kirim tes notifikasi WhatsApp via gateway (pakai setting tersimpan) agar
     * admin bisa memverifikasi konfigurasi. Selalu balas 200 dengan flag `ok`.
     */
    public function adminTestWa(Request $request)
    {
        $validated = $request->validate(['to' => 'required|string|max:20']);

        if (!WhatsAppService::apiKey()) {
            return response()->json([
                'ok'      => false,
                'message' => 'API Key WA belum diatur. Simpan API Key dulu, lalu tes.',
            ]);
        }

        $hasil = WhatsAppService::send(
            $validated['to'],
            'Tes notifikasi WhatsApp Gateway — Koperasi Angkasa Jaya. Jika pesan ini diterima, konfigurasi sudah benar.'
        );

        // Tampilkan id & status antrean dari amplop { success, message, data }
        // supaya admin bisa mencocokkannya dengan Log Pengiriman di bawah.
        $rincian = '';
        if ($hasil['queued_id'] !== null || $hasil['queue_status'] !== null) {
            $rincian = ' (id ' . ($hasil['queued_id'] ?? '-')
                . ', status ' . ($hasil['queue_status'] ?? '-') . ')';
        }

        return response()->json([
            'ok'      => $hasil['ok'],
            'status'  => $hasil['status'],
            'message' => $hasil['ok']
                ? "{$hasil['message']}{$rincian}. Cek WhatsApp nomor tujuan."
                : "Gateway menolak (HTTP " . ($hasil['status'] ?? '-') . "): {$hasil['message']}",
        ]);
    }

    /**
     * Ambil riwayat pesan dari gateway (GET /messages) untuk log pengiriman WA.
     * Meneruskan filter (direction, status, limit, dll). Selalu balas 200 + `ok`.
     */
    public function adminWaMessages(Request $request)
    {
        if (!WhatsAppService::apiKey()) {
            return response()->json(['ok' => false, 'message' => 'API Key WA belum diatur.']);
        }

        $listUrl = WhatsAppService::messagesUrl();

        $params = array_filter(
            $request->only(['page', 'limit', 'deviceId', 'direction', 'status', 'type', 'search', 'from', 'to']),
            fn ($v) => $v !== null && $v !== ''
        );
        $params['limit'] = $params['limit'] ?? 20;

        try {
            $response = WhatsAppService::http()->get($listUrl, $params);

            // Teruskan pesan galat ASLI dari gateway. Sebelumnya hanya kode HTTP
            // yang ditampilkan, sehingga "HTTP 500" tidak memberi petunjuk apa
            // pun — padahal gateway mengirim keterangan yang menunjuk langsung
            // ke akar masalahnya di dalam amplop { success, message }.
            $json = $response->json();
            $pesanGateway = is_array($json) && !empty($json['message'])
                ? (string) $json['message']
                : trim(substr((string) $response->body(), 0, 180));

            return response()->json([
                'ok'      => $response->successful(),
                'status'  => $response->status(),
                'data'    => $json ?? $response->body(),
                'message' => $response->successful()
                    ? null
                    : "Gateway menolak (HTTP {$response->status()})"
                        . ($pesanGateway !== '' ? ": {$pesanGateway}" : ''),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'Gagal menghubungi gateway: ' . $e->getMessage(),
            ]);
        }
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
     * Titik-titik jejak lokasi seorang supir untuk menggambar RUTE di peta admin.
     * Default rentang: hari ini. Bisa override lewat ?from=&to= (datetime).
     */
    public function adminGetDriverRoute(Request $request, $userId)
    {
        $from = $request->query('from')
            ? \Illuminate\Support\Carbon::parse($request->query('from'))
            : now()->startOfDay();
        $to = $request->query('to')
            ? \Illuminate\Support\Carbon::parse($request->query('to'))
            : now();

        $points = \App\Models\DriverLocationLog::where('user_id', $userId)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->limit(2000)
            ->get(['latitude', 'longitude', 'recorded_at'])
            ->map(fn ($p) => [
                'lat' => (float) $p->latitude,
                'lng' => (float) $p->longitude,
                'at'  => optional($p->recorded_at)->toIso8601String(),
            ]);

        return response()->json([
            'driver' => optional(\App\Models\User::find($userId))->name,
            'from'   => $from->toIso8601String(),
            'to'     => $to->toIso8601String(),
            'count'  => $points->count(),
            'points' => $points->values(),
        ]);
    }

    /**
     * Peta supir + rekap keluar-masuk bandara untuk panel Admin.
     * - `drivers`: supir aktif (standby/ontrip) berlokasi valid → marker peta.
     * - `ranking`: SEMUA supir dgn hitungan masuk/keluar, urut terbanyak → tabel.
     */
    public function adminGetDriverLocations()
    {
        $queueScores = \App\Models\DriverQueue::pluck('sort_order', 'user_id');

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
                    'id'              => $user->id,
                    'name'            => $user->name,
                    'line_number'     => $p->line_number,
                    'car_model'       => $p->car_model,
                    'plate_number'    => $p->plate_number,
                    'status'          => $p->status,
                    'queue_score'     => $queueScores[$user->id] ?? null,
                    'latitude'        => (float) $p->latitude,
                    'longitude'       => (float) $p->longitude,
                    'updated_at'      => optional($p->location_updated_at)->toIso8601String(),
                    'in_area'         => (bool) $p->last_in_area,
                    'airport_entries' => (int) $p->airport_entries,
                    'airport_exits'   => (int) $p->airport_exits,
                    // Dugaan fake GPS — lihat DriverApiController::periksaLokasiPalsu().
                    'spoof_strikes'   => (int) $p->spoof_strikes,
                ];
            })
            ->filter()
            ->values();

        $ranking = \App\Models\DriverProfile::with('user:id,name')
            ->get()
            ->map(function ($p) {
                $user = $p->user;
                if (!$user) {
                    return null;
                }
                return [
                    'id'          => $user->id,
                    'name'        => $user->name,
                    'line_number' => $p->line_number,
                    'status'      => $p->status,
                    'in_area'     => (bool) $p->last_in_area,
                    'entries'     => (int) $p->airport_entries,
                    'exits'       => (int) $p->airport_exits,
                ];
            })
            ->filter()
            ->sortByDesc('entries')
            ->values();

        // Daftar pantau dugaan fake GPS. Sengaja dipisah dari `ranking`:
        // mendeteksi tanpa ada yang menindak sama saja dengan tidak mendeteksi,
        // jadi angka ini harus muncul sebagai daftar tersendiri yang pendek dan
        // menuntut keputusan — bukan satu kolom yang tenggelam di tabel besar.
        // Yang ditampilkan hanya supir yang PERNAH kena, terbanyak di atas.
        $spoofWatch = \App\Models\DriverProfile::with('user:id,name')
            ->where('spoof_strikes', '>', 0)
            ->orderByDesc('spoof_strikes')
            ->get()
            ->map(function ($p) {
                $user = $p->user;
                if (!$user) {
                    return null;
                }
                return [
                    'id'          => $user->id,
                    'name'        => $user->name,
                    'line_number' => $p->line_number,
                    'strikes'     => (int) $p->spoof_strikes,
                    // 'mock_provider' = Android sendiri menandai lokasinya palsu
                    // (bukti tegas). 'teleport' = lompatan mustahil antar-ping
                    // (bukti tak langsung, bisa juga GPS rusak).
                    'last_reason' => $p->last_spoof_reason,
                    'last_at'     => optional($p->last_spoof_at)->toIso8601String(),
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
            'drivers'     => $drivers,
            'ranking'     => $ranking,
            'spoof_watch' => $spoofWatch,
        ]);
    }

    // =========================================================
    // === MANAGEMENT API KEYS (akses sistem eksternal koperasi) ===
    // =========================================================

    public function adminGetApiClients()
    {
        $clients = \App\Models\ApiClient::orderByDesc('created_at')->get()->map(fn ($c) => [
            'id'           => $c->id,
            'name'         => $c->name,
            'key_prefix'   => $c->key_prefix,
            'active'       => (bool) $c->active,
            'last_used_at' => optional($c->last_used_at)->toIso8601String(),
            'created_at'   => optional($c->created_at)->toIso8601String(),
        ]);
        return response()->json($clients);
    }

    public function adminStoreApiClient(Request $request)
    {
        $validated = $request->validate(['name' => 'required|string|max:100']);
        [$plain, $client] = \App\Models\ApiClient::generate($validated['name']);
        return response()->json([
            'message' => 'API key dibuat. Salin sekarang — kunci lengkap tidak akan ditampilkan lagi.',
            'key'     => $plain,
            'client'  => [
                'id'         => $client->id,
                'name'       => $client->name,
                'key_prefix' => $client->key_prefix,
            ],
        ], 201);
    }

    public function adminRevokeApiClient($id)
    {
        $client = \App\Models\ApiClient::find($id);
        if (!$client) {
            return response()->json(['message' => 'API key tidak ditemukan.'], 404);
        }
        $client->delete();
        return response()->json(['message' => 'API key dicabut.']);
    }

    /**
     * Pratinjau response Management API dari panel admin (auth pakai sesi admin,
     * bukan API key). Meneruskan ke ManagementApiController agar admin bisa lihat
     * BENTUK & ISI data yang sama dengan yang diterima sistem eksternal.
     */
    public function adminApiPreview(Request $request, ManagementApiController $mgmt)
    {
        return match ((string) $request->query('endpoint', 'me')) {
            'transactions'    => $mgmt->transactions($request),
            'revenue/summary' => $mgmt->revenueSummary($request),
            'drivers'         => $mgmt->drivers($request),
            'withdrawals'     => $mgmt->withdrawals($request),
            default           => response()->json([
                'system'      => config('app.name'),
                'client'      => '(pratinjau via akun admin)',
                'scope'       => 'read-only',
                'server_time' => now()->toIso8601String(),
            ]),
        };
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

