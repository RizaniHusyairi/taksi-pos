<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\Withdrawals;
use App\Models\Setting;
use App\Models\AdminNotification;
use App\Models\DriverQueue;
use App\Services\AdminNotifier;
use App\Services\CommissionCalculator;
use App\Services\PushNotifier;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\WithdrawalRequestNotification;

class DriverApiController extends Controller
{

    /**
     * Helper untuk menyuntikkan "Status Virtual" ke objek user
     * agar Frontend JS tidak error.
     */
    /**
     * Helper untuk memastikan status sinkron dengan antrian real.
     */
    private function attachVirtualStatus($user)
    {
        // 1. Cek apakah user benar-benar ada di tabel antrian?
        $isInQueue = DriverQueue::where('user_id', $user->id)->exists();

        // 2. LOGIKA PERBAIKAN (Sanity Check):
        // Jika Driver TIDAK ADA di antrian, TAPI status di profil masih 'standby'...
        // Itu berarti error / data nyangkut. Kita harus koreksi jadi 'offline'.
        if (!$isInQueue && $user->driverProfile->status === 'standby') {
            
            // Ubah objek user yang akan dikirim ke frontend
            $user->driverProfile->status = 'offline';
            
            // PERBAIKAN PERMANEN: Update database sekalian agar tidak nyangkut lagi
            $user->driverProfile->update(['status' => 'offline']);
        }
        
        // Catatan: Jika status 'ontrip', biarkan saja (karena memang tidak ada di queue)

        return $user;
    }


    /**
     * Mengambil data utama untuk driver: info profil dan order aktif.
     */
    public function getProfile(Request $request)
    {
        // Load relasi profile
        $driver = $request->user()->load('driverProfile');

        // Suntikkan status virtual (berdasarkan tabel queue)
        $driver = $this->attachVirtualStatus($driver);

        // Cek Booking Aktif (Logika lama)
        $activeBooking = null;
        // Kita anggap jika sedang 'ontrip' itu didapat dari Booking yang belum selesai
        // Bukan dari status profile lagi.
        // --- PERBAIKAN DI SINI ---
        $ongoingBooking = Booking::where('driver_id', $driver->id)
            // HAPUS 'Paid' dan 'CashDriver' dari daftar pengecualian (whereNotIn)
            // Kita hanya ingin menyembunyikan order yang sudah Selesai (Completed) atau Dibatalkan (Cancelled)
            ->whereNotIn('status', ['Completed', 'Cancelled']) 
            ->latest()
            ->with(['zoneTo:id,name', 'cso:id,name', 'transaction:id,booking_id,method'])
            ->first();
        

        // Hitung Posisi Antrian (Jika standby)
        if ($driver->driverProfile && $driver->driverProfile->status === 'standby') {
            $myQueue = DriverQueue::where('user_id', $driver->id)->first();
            if ($myQueue) {
                // Posisi = jumlah antrian yang berada DI DEPAN saya + 1.
                // Pemecah seri created_at wajib ada: seluruh supir rejoin
                // berbagi sort_order 1000, jadi tanpa ini mereka semua melihat
                // nomor yang sama — dan berbeda dari urutan yang benar-benar
                // dipakai CSO (lihat CsoApiController::readyQueueQuery:
                // sort_order ASC, lalu created_at ASC).
                $position = DriverQueue::where(function ($q) use ($myQueue) {
                        $q->where('sort_order', '<', $myQueue->sort_order)
                          ->orWhere(function ($sama) use ($myQueue) {
                              $sama->where('sort_order', $myQueue->sort_order)
                                   ->where('created_at', '<', $myQueue->created_at);
                          });
                    })->count() + 1;
                $driver->queue_position = $position;
            } else {
                $driver->queue_position = null;
            }
        } else {
            $driver->queue_position = null;
        }

        $driver->active_booking = $ongoingBooking;

        // Info jam operasi → app pakai untuk menyalakan ulang layanan lokasi
        // saat jendela kerja dibuka (dan menampilkan status "di luar jam").
        $oh = Setting::operatingHours();
        $driver->operating_hours = [
            'start' => $oh['start'],
            'end'   => $oh['end'],
            'open'  => Setting::isWithinOperatingHours(),
        ];

        return response()->json($driver);
    }
    /**
     * Mengubah status driver (available/offline).
     */
    public function setStatus(Request $request)
    {
        // ... (Validasi tetap sama) ...
        $validated = $request->validate([
            'action' => 'required|in:join,leave',
            'latitude'  => 'required_if:action,join|numeric|between:-90,90',
            'longitude' => 'required_if:action,join|numeric|between:-180,180',
            // `is_mocked` dibaca lewat bacaFlagMock(), tidak divalidasi di sini
            // (lihat updateLocation). Pintu masuk antrian dijaga sama ketatnya
            // dengan ping berkala — kalau tidak, supir cukup memalsukan lokasi
            // sekali saja tepat ketika menekan "Masuk Antrian".
            'reason'             => 'required_if:action,leave|in:self,other',
            'manual_destination' => 'required_if:reason,self|string|nullable',
            'manual_price'       => 'required_if:reason,self|numeric|min:0',
        ]);

        $user = $request->user();

        $profile = $user->driverProfile;

        if ($validated['action'] === 'join') {
            // GERBANG ANTI FAKE GPS — sebelum pemeriksaan jarak. Koordinat
            // palsu yang "kebetulan" tepat di bandara akan lolos uji jarak
            // dengan mulus, jadi keasliannya harus diuji lebih dulu.
            $alasanPalsu = $this->periksaLokasiPalsu(
                $profile,
                (float) $request->latitude,
                (float) $request->longitude,
                $this->bacaFlagMock($request)
            );

            if ($alasanPalsu !== null) {
                $this->hukumLokasiPalsu($profile, $user->id, $alasanPalsu);

                return response()->json([
                    'message' => $alasanPalsu === 'mock_provider'
                        ? 'Lokasi palsu terdeteksi. Matikan aplikasi fake GPS terlebih dahulu.'
                        : 'Perpindahan lokasi tidak wajar. Tunggu sebentar lalu coba lagi.',
                    'spoof_detected' => true,
                    'reason'         => $alasanPalsu,
                ], 422);
            }

            // GERBANG ORDER AKTIF. Didahulukan dari gerbang utang karena
            // inilah alasan yang paling mendesak & paling bisa ditindaklanjuti
            // supir saat itu juga ("selesaikan ordermu"), dan pemeriksaannya
            // pun paling murah (satu exists()).
            if ($pesanOrder = $this->gerbangOrderAktif($user)) {
                return response()->json([
                    'message'           => $pesanOrder,
                    'has_active_order'  => true,
                ], 422);
            }

            // GERBANG UTANG. Ditaruh setelah pemeriksaan lokasi palsu tapi
            // sebelum supir benar-benar masuk antrian: percuma memberi giliran
            // kepada supir yang uang koperasinya sudah menumpuk di kantongnya.
            if ($pesanUtang = $this->gerbangUtang($user)) {
                return response()->json([
                    'message'      => $pesanUtang,
                    'debt_blocked' => true,
                ], 422);
            }

            $airportLat = Setting::airportLatitude();
            $airportLng = Setting::airportLongitude();
            $distance = $this->calculateDistance($airportLat, $airportLng, $request->latitude, $request->longitude);

            // Radius dari Pengaturan admin (bukan config mentah) supaya jalur
            // join memakai geofence yang sama dengan pengecekan in_area.
            if ($distance > Setting::airportRadiusKm()) {
                return response()->json(['message' => 'Terlalu jauh dari bandara.'], 422);
            }

            // RESET BLOCKED STATUS — sengaja SETELAH pemeriksaan jarak. Kalau
            // dibersihkan lebih dulu, permintaan join yang ditolak pun tetap
            // mematikan blokir, sehingga supir yang tadi dikeluarkan otomatis
            // bisa tertarik masuk lagi oleh auto-join tanpa tindakan sadar.
            $profile->update(['auto_join_blocked' => false]);

            $today = now()->toDateString();
            $sortOrder = $this->queueSortOrderFor($profile);

            // Simpan ke antrian memakai sort_order hasil ROTASI:
            //  - first-join hari ini  -> posisi giliran (mis. 0,1,2,... berdasar line_number)
            //  - re-join              -> 1000 (antrian belakang / grup "Rejoin")
            // Sebelumnya keliru memakai ($maxOrder + 1) sehingga rotasi tidak pernah berlaku
            // dan semua driver selalu masuk ke belakang antrian.
            DriverQueue::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'sort_order' => $sortOrder,
                    // Catatan: TIDAK menyimpan 'joined_at' — kolomnya tidak ada di tabel &
                    // bukan $fillable, jadi selama ini di-drop diam-diam. Waktu masuk antrian
                    // sudah otomatis tercatat di created_at (baris antrian dibuat ulang tiap
                    // join karena 'leave' menghapusnya); admin pun membaca created_at.
                ]
            );

            $profile->update([
                'last_queue_date' => $today,
                'status' => 'standby', // Update status jadi standby
                // Koordinat join ADALAH bukti kehadiran terbaru. Wajib dicatat:
                // penyapu `queue:sweep-stale` menilai kehadiran dari
                // location_updated_at, jadi tanpa ini supir yang baru saja
                // menekan "Masuk Antrian" bisa langsung tersapu keluar.
                'latitude'            => $request->latitude,
                'longitude'           => $request->longitude,
                'location_updated_at' => now(),
            ]);
            
            // LOG ACTIVITY
            $this->logActivity($user->id, 'QUEUE_JOIN', 'Masuk Antrian');

            $msg = 'Berhasil masuk antrian';

        } else {
            // --- LOGIKA LEAVE (KELUAR) ---
            
            DB::transaction(function () use ($user, $validated, $request) {
                
                // 1. Hapus dari Antrian
                DriverQueue::where('user_id', $user->id)->delete();

                // 2. Jika alasan "Dapat Penumpang Sendiri"
                if ($request->reason === 'self') {
                    
                    // A. Buat Booking Start OnTrip
                    Booking::create([
                        'cso_id'             => $user->id, 
                        'driver_id'          => $user->id,
                        'zone_id'            => null,
                        'manual_destination' => $request->manual_destination,
                        'price'              => $request->manual_price,
                        'status'             => 'OnTrip',
                    ]);

                    // B. Update Status Profil jadi 'ontrip'
                    $user->driverProfile()->update(['status' => 'ontrip']);

                    // LOG ACTIVITY
                    $this->logActivity($user->id, 'TRIP_START_SELF', 'Mulai Trip Mandiri: ' . $request->manual_destination);

                } else {
                    // Keluar Biasa / Off
                    $user->driverProfile()->update(['status' => 'offline']);
                    
                    // LOG ACTIVITY
                    $this->logActivity($user->id, 'QUEUE_LEAVE', 'Keluar Antrian (Istirahat/Lainnya)');
                }
            });

            $msg = 'Berhasil keluar antrian';
            if($request->reason === 'self') $msg .= ' (Self Passenger)';
        }



        $user->load('driverProfile');
        $userWithStatus = $this->attachVirtualStatus($user);

        return response()->json([
            'message' => $msg,
            'data' => $userWithStatus
        ]);
    }

    /**
     * Posisi antrian untuk supir yang BARU masuk — dipakai jalur join manual
     * MAUPUN auto-join, supaya kedua pintu masuk tidak pernah lagi memakai
     * aturan berbeda (dulu auto-join memakai max+1 sehingga rotasi harian
     * tidak pernah berlaku, bahkan bisa menaruh first-join di belakang grup
     * rejoin yang ber-sort_order 1000).
     *
     *  - kedatangan PERTAMA hari ini -> urutan giliran hasil rotasi
     *    (line_number vs daily_start_line), bernilai 0..jumlah_supir
     *  - kedatangan berikutnya       -> 1000 (grup "Rejoin", antrian belakang)
     */
    private function queueSortOrderFor($profile): int
    {
        $isFirstJoinToday = ($profile->last_queue_date !== now()->toDateString());

        if (!$isFirstJoinToday || !$profile->line_number) {
            return 1000;
        }

        // Ambil Angka Giliran Hari Ini (Default 1 jika error)
        $dailyStart = (int) Setting::getValue('daily_start_line') ?: 1;
        $myLine = (int) $profile->line_number;
        $totalDrivers = \App\Models\DriverProfile::max('line_number') ?: 30; // jumlah driver dinamis

        // Rumus Matematika Rotasi
        if ($myLine >= $dailyStart) {
            // Kasus Normal: Giliran 5, Saya 6. Posisi = 6 - 5 = 1 (Urutan ke-2 karena index 0)
            return $myLine - $dailyStart;
        }

        // Kasus Wrapping: Giliran 28, Saya 2. Saya harus di bawah 30.
        // Posisi = (30 - 28) + 2 = 4.
        return ($totalDrivers - $dailyStart) + $myLine;
    }

    /**
     * Fungsi helper menghitung jarak dua titik koordinat (Haversine Formula)
     * Return dalam Kilometer
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2) {
        $earthRadius = 6371; 

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon/2) * sin($dLon/2);

        $c = 2 * atan2(sqrt($a), sqrt(1-$a));

        return $earthRadius * $c;
    }

    // =====================================================================
    // ===  DETEKSI LOKASI PALSU (FAKE GPS)                               ===
    // =====================================================================

    /**
     * Apakah satu fix lokasi patut dicurigai palsu?
     *
     * Seluruh mesin antrian mempercayai koordinat kiriman klien, jadi inilah
     * satu-satunya tempat kepercayaan itu diuji. Dua detektor yang sengaja
     * saling menutupi kelemahan masing-masing:
     *
     *  - `mock_provider`: Android sendiri menandai fix-nya berasal dari mock
     *    provider (Position.isMocked). Bukti paling tegas yang bisa didapat —
     *    tapi lenyap begitu supir memakai APK modifikasi atau memanggil API
     *    langsung dengan curl.
     *  - `teleport`: jarak dari fix tersimpan sebelumnya dibagi waktu tempuh
     *    menghasilkan kecepatan yang mustahil bagi mobil. Dihitung SEPENUHNYA
     *    di server dari data yang sudah tersimpan, jadi tidak ada yang bisa
     *    dimatikan dari sisi klien.
     *
     * Ambangnya sengaja longgar (lihat config/taksi.php): tugasnya menangkap
     * lompatan puluhan kilometer dalam hitungan detik, bukan supir yang ngebut.
     *
     * @return string|null  alasan ('mock_provider'|'teleport'), null bila wajar
     */
    /**
     * Baca flag `is_mocked` dengan longgar: true/false, 1/0, "true"/"false".
     *
     * SENGAJA tidak divalidasi dengan rule `boolean`. Flag ini cuma petunjuk
     * dari perangkat, sedangkan ping lokasi adalah denyut nadi antrian — nilai
     * yang tidak bisa dibaca harus berarti "tidak tahu", BUKAN alasan menolak
     * seluruh ping. Rule `boolean` menolak string "false" (yang muncul begitu
     * ada klien mengirim form-encoded, bukan JSON) dan akibatnya pelacakan
     * lokasi supir mati total karena sebuah petunjuk opsional.
     *
     * @return bool|null  null = perangkat tidak memberi tahu
     */
    private function bacaFlagMock(Request $request): ?bool
    {
        if (!$request->has('is_mocked')) {
            return null;
        }

        // FILTER_NULL_ON_FAILURE: nilai aneh -> null ("tidak tahu"), bukan false.
        return filter_var($request->input('is_mocked'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function periksaLokasiPalsu($profile, float $lat, float $lng, ?bool $isMocked): ?string
    {
        if ($isMocked === true) {
            return 'mock_provider';
        }

        // Butuh fix sebelumnya sebagai pembanding. (0,0) adalah sentinel
        // "belum ada fix" yang memang dipakai pre-fill rotasi harian — bukan
        // baseline yang sah untuk menghitung jarak.
        if (!$profile || !$profile->location_updated_at
            || $profile->latitude === null || $profile->longitude === null) {
            return null;
        }

        $lastLat = (float) $profile->latitude;
        $lastLng = (float) $profile->longitude;
        if ($lastLat === 0.0 && $lastLng === 0.0) {
            return null;
        }

        // Jam perangkat bisa meleset; selisih negatif diperlakukan sebagai
        // "tidak bisa dinilai", bukan sebagai bukti.
        $detik = now()->timestamp - $profile->location_updated_at->timestamp;
        if ($detik < (int) config('taksi.driver_queue.teleport_min_seconds')) {
            return null;
        }

        $km = $this->calculateDistance($lastLat, $lastLng, $lat, $lng);
        if ($km < (float) config('taksi.driver_queue.teleport_min_km')) {
            return null;
        }

        $kmh = $km / ($detik / 3600);

        return $kmh > (float) config('taksi.driver_queue.teleport_speed_kmh')
            ? 'teleport'
            : null;
    }

    /**
     * Catat dugaan pemalsuan lokasi dan tentukan hukumannya.
     *
     * Hukumannya SENGAJA berbeda menurut kekuatan buktinya:
     *
     *  - `mock_provider` — tidak ada tafsir lain: keluarkan dari antrian dan
     *    matikan auto-join, supaya supir harus menekan "Masuk Antrian" secara
     *    sadar (dan pemeriksaan ini berjalan lagi di sana).
     *  - `teleport` — bukti tak langsung, dan GPS yang rusak sesekali bisa
     *    menghasilkannya. Fix-nya cukup DITOLAK, antriannya tidak diutak-atik.
     *    Efeknya tetap nyata tanpa risiko salah tuduh: ping yang ditolak tidak
     *    menyegarkan `location_updated_at`, sehingga supir yang benar-benar
     *    memalsukan lokasi akan berhenti dianggap hadir dan tersapu sendiri
     *    oleh `queue:sweep-stale` — sementara supir yang cuma apes satu fix
     *    langsung pulih di ping berikutnya.
     */
    private function hukumLokasiPalsu($profile, int $userId, string $alasan): void
    {
        // Supir tanpa baris profil: tidak ada tempat menyimpan strike, tapi
        // fix-nya tetap ditolak oleh pemanggil dan kejadiannya tetap tercatat.
        // (Jalur `is_mocked` bisa sampai ke sini tanpa profil — pemeriksaan
        // teleport tidak, karena ia butuh baseline dari profil.)
        if (!$profile) {
            $this->logActivity($userId, 'LOCATION_SPOOF_SUSPECT', "Lokasi palsu ({$alasan}) — supir tanpa profil");
            return;
        }

        $profile->increment('spoof_strikes');
        $profile->update([
            'last_spoof_at'     => now(),
            'last_spoof_reason' => $alasan,
        ]);

        if ($alasan === 'mock_provider') {
            DriverQueue::where('user_id', $userId)->delete();
            $profile->update([
                'status'            => 'offline',
                'out_of_area_since' => null,
                'auto_join_blocked' => true,
            ]);

            $this->logActivity(
                $userId,
                'LOCATION_SPOOF_BLOCKED',
                'Lokasi palsu terdeteksi (mock provider) — dikeluarkan dari antrian'
            );

            return;
        }

        $this->logActivity(
            $userId,
            'LOCATION_SPOOF_SUSPECT',
            'Lompatan lokasi mustahil — fix ditolak (dugaan fake GPS)'
        );
    }

    /**
     * Menyelesaikan perjalanan.
     */
    public function completeBooking(Request $request, Booking $booking)
    {
        if ($request->user()->id !== $booking->driver_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Hanya order yang sedang berjalan (OnTrip) yang boleh diselesaikan.
        // Mencegah "menyelesaikan" order yang belum dimulai (Assigned),
        // sudah selesai (Completed), atau dibatalkan (Cancelled). Penting:
        // tanpa cek ini, blok self-order di bawah bisa membuat catatan
        // hutang (Transaction) ganda untuk order yang sama.
        if ($booking->status !== 'OnTrip') {
            return response()->json([
                'message' => 'Order ini tidak bisa diselesaikan (status saat ini: ' . $booking->status . ').',
            ], 422);
        }

        // Update status booking saja.
        // Tidak perlu update status driver_profile (karena kolomnya sudah dihapus).
        // Driver otomatis jadi 'offline' (tidak di queue) setelah trip selesai.

        // --- NEW LOGIC FOR SELF PASSENGER ---
        // Jika ini adalah Self Order (CSO = Driver, Zone = Null)
        if ($booking->cso_id == $request->user()->id && is_null($booking->zone_id)) {
            // Catat sebagai Hutang (Unpaid Transaction)
            Transaction::create([
                'booking_id'    => $booking->id,
                'method'        => 'CashDriver', // Uang di driver
                'amount'        => $booking->price, // Nominal tarif yang diinput
                'payout_status' => 'Unpaid', // Belum disetor
            ]);
        }
        
        $booking->update(['status' => 'Completed']);

        // 2. PERBAIKAN PENTING DI SINI
        // Reset status profil driver menjadi 'offline'
        // Agar UI kembali ke mode awal (tombol "Masuk Antrian" muncul)
        $request->user()->driverProfile()->update(['status' => 'offline']);
        
        // LOG ACTIVITY
        $this->logActivity($request->user()->id, 'TRIP_FINISH', 'Selesai Trip No. ' . $booking->id);

        // Panggil getProfile untuk mengembalikan data terbaru ke frontend
        return $this->getProfile($request);
        
    }

    /**
     * Mengambil saldo dompet driver.
     */
    public function getBalance(Request $request)
    {
        return response()->json($this->hitungSaldo($request->user()));
    }

    /** Batas baris yang dikirim ke aplikasi (mengikuti getTripHistory). */
    private const BATAS_RINCIAN = 200;

    /**
     * Transaksi di balik satu angka pada kartu Rincian Saldo.
     *
     * Kartu itu sudah memperlihatkan RUMUSNYA, tapi berhenti di jumlah agregat:
     * "18 transaksi", "3 trip". Supir bisa melihat berapa, tidak bisa melihat
     * dari mana. Untuk fitur yang menyangkut uang, angka yang tidak bisa
     * ditelusuri adalah angka yang tidak bisa dipercaya.
     *
     * Memakai kueri yang SAMA PERSIS dengan hitungSaldo(), sehingga penjumlahan
     * baris di sini tidak mungkin berbeda dari angka yang tampil di kartu.
     */
    public function balanceTransactions(Request $request)
    {
        $validated = $request->validate([
            'bucket' => 'required|in:income,debt_standard,debt_manual',
        ]);

        $driver = $request->user();
        $bucket = $validated['bucket'];

        $rate = (float) Setting::getValue('commission_rate') ?: 0.2;
        $flat = (int) (Setting::getValue('manual_fee_flat') ?: 10000);

        [$query, $label] = match ($bucket) {
            'income'        => [$this->queryPemasukan($driver), 'Pemasukan'],
            'debt_standard' => [$this->queryUtangZona($driver), 'Setoran komisi tunai'],
            'debt_manual'   => [$this->queryUtangManual($driver), 'Biaya order manual'],
        };

        $jumlah = (clone $query)->count();

        // Pada bucket manual zone_id selalu null, jadi memuat relasi zona hanya
        // sia-sia — dan pada PHP 8.5 memicu peringatan deprecation dari
        // Eloquent saat mencocokkan kunci null.
        $muat = ['booking:id,zone_id,manual_destination'];
        if ($bucket !== 'debt_manual') {
            $muat[] = 'booking.zoneTo:id,name';
        }

        $rows = $query->with($muat)
            ->orderByDesc('created_at')
            ->limit(self::BATAS_RINCIAN)
            ->get(['id', 'booking_id', 'method', 'amount', 'created_at']);

        $items = $rows->map(function (Transaction $t) use ($bucket, $rate, $flat) {
            return [
                'id'         => $t->id,
                'booking_id' => $t->booking_id,
                'date'       => $t->created_at->toIso8601String(),
                'destination' => $t->booking?->zoneTo?->name
                    ?: ($t->booking?->manual_destination ?: 'Order manual'),
                'method'     => $t->method,
                'amount'     => round((float) $t->amount),
                'contribution' => round($this->kontribusiBaris($t, $bucket, $rate, $flat)),
            ];
        })->values();

        return response()->json([
            'bucket'    => $bucket,
            'label'     => $label,
            'count'     => $jumlah,
            'truncated' => $jumlah > self::BATAS_RINCIAN,
            'total'     => round($items->sum('contribution')),
            'items'     => $items,
        ]);
    }

    /**
     * Berapa yang disumbang satu transaksi ke angka pada kartu.
     *
     * PERHATIAN — kedua sisi sengaja BERBEDA rumus, mengikuti hitungSaldo():
     *
     *  - sisi UTANG memakai CommissionCalculator, yang tahu bedanya order
     *    berzona (persentase) dan order manual (tarif flat);
     *  - sisi PEMASUKAN memakai persentase rata untuk semua order, termasuk
     *    order manual. Memakai CommissionCalculator di sini akan mengenakan
     *    tarif flat pada order manual, sehingga penjumlahan baris tidak lagi
     *    cocok dengan "Komisi koperasi" yang tampil di kartu.
     *
     * (Bahwa satu order manual bisa dikenai biaya berbeda tergantung siapa
     * yang memegang uangnya adalah perilaku lama, bukan sesuatu yang diubah
     * di sini — itu menyangkut uang dan perlu dibahas terpisah.)
     */
    private function kontribusiBaris(Transaction $t, string $bucket, float $rate, int $flat): float
    {
        return $bucket === 'income'
            ? (float) $t->amount * $rate
            : CommissionCalculator::compute($t, $rate, $flat);
    }

    /**
     * Rumus dompet supir — SATU-SATUNYA tempat pemasukan, utang, dan saldo
     * bersih dihitung.
     *
     * Dipisah dari getBalance() karena angkanya sekarang dipakai di tiga
     * tempat dengan tujuan berbeda: menampilkan dompet, mengunci nominal saat
     * pencairan, dan menjaga pintu antrian (lihat gerbangUtang()). Kalau
     * rumusnya sampai bercabang, supir bisa melihat angka yang berbeda dari
     * angka yang dipakai memblokirnya — dan itu jauh lebih merusak
     * kepercayaan daripada bug hitungan biasa.
     */
    /**
     * Ketiga kantong pembentuk dompet supir, masing-masing SATU definisi.
     *
     * Dipakai dua kali dengan tujuan berbeda: dijumlahkan oleh hitungSaldo()
     * untuk angka di kartu, dan dibaca baris demi baris oleh
     * balanceTransactions() untuk memperlihatkan asal angkanya. Kalau daftar
     * dan totalnya dihitung dari dua definisi terpisah, cepat atau lambat
     * keduanya berselisih — dan layar yang justru dibuat untuk membangun
     * kepercayaan akan menghancurkannya.
     */
    private function queryPemasukan(User $driver)
    {
        // Uang sudah ada di sistem (QRIS / tunai ke kasir) dan belum dicairkan.
        return Transaction::whereHas('booking', function ($q) use ($driver) {
                $q->where('driver_id', $driver->id)->where('status', 'Completed');
            })
            ->whereIn('method', ['QRIS', 'CashCSO'])
            ->where('payout_status', 'Unpaid');
    }

    /** Tunai dipegang supir, order BERZONA → potongan persentase. */
    private function queryUtangZona(User $driver)
    {
        return Transaction::whereHas('booking', function ($q) use ($driver) {
                $q->where('driver_id', $driver->id)
                  ->where('status', 'Completed')
                  ->whereNotNull('zone_id');
            })
            ->where('method', 'CashDriver')
            ->where('payout_status', 'Unpaid');
    }

    /** Tunai dipegang supir, order MANUAL (tanpa zona) → tarif flat per trip. */
    private function queryUtangManual(User $driver)
    {
        return Transaction::whereHas('booking', function ($q) use ($driver) {
                $q->where('driver_id', $driver->id)
                  ->where('status', 'Completed')
                  ->whereNull('zone_id');
            })
            ->where('method', 'CashDriver')
            ->where('payout_status', 'Unpaid');
    }

    private function hitungSaldo(User $driver): array
    {
        // Rate Komisi (mis. 0.2 = 20%). Disimpan desimal di settings.
        $rate = (float) Setting::getValue('commission_rate') ?: 0.2;
        $manualFlat = (int) (Setting::getValue('manual_fee_flat') ?: 10000);

        // === 1. PEMASUKAN (uang ada di sistem → hak driver) ===
        //     QRIS & CashCSO yang masih 'Unpaid'.
        $incomeQ = $this->queryPemasukan($driver);
        $incomeGross = (float) (clone $incomeQ)->sum('amount');
        $incomeCount = (clone $incomeQ)->count();
        $commission  = $incomeGross * $rate;          // potongan komisi koperasi
        $driverShare = $incomeGross - $commission;    // hak bersih driver

        // === 2. HUTANG (uang dipegang driver → setoran ke koperasi) ===
        // A. Booking via sistem (ada zona) → kena komisi rate.
        $stdQ = $this->queryUtangZona($driver);
        $standardGross    = (float) (clone $stdQ)->sum('amount');
        $standardCount    = (clone $stdQ)->count();
        $debtFromStandard = $standardGross * $rate;

        // B. Booking manual (tanpa zona) → fee flat per trip.
        $manualCount    = $this->queryUtangManual($driver)->count();
        $debtFromManual = $manualCount * $manualFlat;

        $driverDebt = $debtFromStandard + $debtFromManual;

        // === 3. SALDO BERSIH ===
        $netBalance = $driverShare - $driverDebt;

        $batasUtang = Setting::maxDriverDebt();

        return [
            // Kunci lama (kompatibilitas mundur)
            'balance'        => round($netBalance),
            'income_pending' => round($driverShare),
            'debt_pending'   => round($driverDebt),
            // Batas utang & sisa jatah — ditampilkan di dompet supaya supir
            // melihat dirinya mendekati batas SEBELUM antriannya diblokir,
            // bukan mendadak ditolak di gerbang tanpa penjelasan.
            'debt_limit'     => $batasUtang,
            'debt_remaining' => $batasUtang > 0 ? max(0, $batasUtang - (int) round($driverDebt)) : null,
            'debt_blocked'   => $batasUtang > 0 && round($driverDebt) > $batasUtang,
            // Rincian lengkap untuk UI dompet yang transparan
            'breakdown' => [
                'commission_rate' => $rate,
                'manual_fee_flat' => $manualFlat,
                'income' => [
                    'gross'      => round($incomeGross),
                    'commission' => round($commission),
                    'net'        => round($driverShare),
                    'count'      => $incomeCount,
                ],
                'debt' => [
                    'standard_gross' => round($standardGross),
                    'standard_fee'   => round($debtFromStandard),
                    'standard_count' => $standardCount,
                    'manual_count'   => $manualCount,
                    'manual_fee'     => round($debtFromManual),
                    'total'          => round($driverDebt),
                ],
                'net' => round($netBalance),
            ],
        ];
    }

    /**
     * Bolehkah supir ini masuk antrian, dilihat dari order yang sedang jalan?
     *
     * BUKAN sekadar pengetatan — tanpa ini supir masuk antrian lagi SENDIRINYA,
     * tanpa niat curang. Rantainya: processOrder() mengeluarkan supir dari
     * antrian tapi tidak mengubah status profilnya (tetap 'standby');
     * attachVirtualStatus() lalu melihat "standby tapi tidak ada di antrian"
     * sebagai data nyangkut dan mengoreksinya jadi 'offline'; auto-join di
     * updateLocation() menarik siapa pun yang 'offline' & berada di area.
     * Hasilnya supir dengan order 'Assigned' yang belum ditekan "Mulai"
     * nangkring lagi di antrian.
     *
     * Akibatnya nyata: processOrder() menolak memberi order kepada supir yang
     * punya order aktif, jadi CSO melihat supir itu di puncak antrian tapi
     * tidak bisa memilihnya — dan terpaksa melewati giliran. Override yang
     * seharusnya jarang jadi rutin, lalu laporan override kehilangan artinya.
     *
     * @return string|null  pesan penolakan, atau null bila boleh
     */
    private function gerbangOrderAktif(User $driver): ?string
    {
        $aktif = Booking::where('driver_id', $driver->id)
            ->whereIn('status', ['Assigned', 'OnTrip'])
            ->first(['id', 'status']);

        if (!$aktif) {
            return null;
        }

        return $aktif->status === 'Assigned'
            ? 'Anda masih punya order yang belum dijalankan (order #' . $aktif->id
                . '). Selesaikan dulu, baru bisa masuk antrian lagi.'
            : 'Anda sedang mengantar penumpang (order #' . $aktif->id
                . '). Tekan "Selesai" dulu, baru bisa masuk antrian lagi.';
    }

    /**
     * Bolehkah supir ini masuk antrian, dilihat dari utangnya?
     *
     * @return string|null  pesan penolakan, atau null bila boleh
     */
    private function gerbangUtang(User $driver): ?string
    {
        $batas = Setting::maxDriverDebt();

        if ($batas <= 0) {
            return null; // admin mematikan pembatasan
        }

        $utang = (int) round($this->hitungSaldo($driver)['debt_pending']);

        if ($utang <= $batas) {
            return null;
        }

        return 'Utang setoran Anda Rp ' . number_format($utang, 0, ',', '.')
            . ' sudah melewati batas Rp ' . number_format($batas, 0, ',', '.')
            . '. Silakan setor tunai ke admin lebih dulu lewat menu Setoran.';
    }

    /**
     * Mengajukan penarikan dana.
     */
    public function requestWithdrawal(Request $request)
    {
        $driver = $request->user();
        
        // --- VALIDASI : Cek Rekening ---
        if (!$driver->driverProfile || empty($driver->driverProfile->account_number)) {
            return response()->json([
                'message' => 'Anda belum mengatur rekening pencairan. Silakan isi nomor rekening Bank BTN di menu Profil.'
            ], 422); // 422 Unprocessable Entity
        }
        

        // 1. Cek Saldo lagi untuk memastikan
        $amountToWithdraw = $this->hitungSaldo($driver)['balance'];

        if ($amountToWithdraw < 10000) {
            return response()->json(['message' => 'Saldo bersih belum mencapai batas minimal pencairan (Rp 10.000).'], 422);
        }

        // Variabel untuk menampung objek withdrawal agar bisa dikirim ke email
        $newWithdrawal = null;

        // 2. Mulai Transaksi Database
        DB::transaction(function () use ($driver, $amountToWithdraw, &$newWithdrawal) {
            
            // A. Buat Record Penarikan
            $newWithdrawal = $driver->withdrawals()->create([
                'amount' => $amountToWithdraw,
                'status' => 'Pending',
                'requested_at' => now(),
            ]);

            // B. KUNCI TRANSAKSI (Ubah status 'Unpaid' -> 'Processing')
            
            // Update transaksi Pemasukan (QRIS/CashCSO)
            Transaction::whereHas('booking', function ($q) use ($driver) {
                $q->where('driver_id', $driver->id);
            })
            ->whereIn('method', ['QRIS', 'CashCSO'])
            ->where('payout_status', 'Unpaid')
            ->update(['payout_status' => 'Processing', 'withdrawal_id' => $newWithdrawal->id]); // Tandai sedang diproses

            // Update transaksi Hutang (CashDriver) - Dianggap lunas/dipotong saat pencairan ini sukses
            Transaction::whereHas('booking', function ($q) use ($driver) {
                $q->where('driver_id', $driver->id);
            })
            ->where('method', 'CashDriver')
            ->where('payout_status', 'Unpaid')
            ->update(['payout_status' => 'Processing', 'withdrawal_id' => $newWithdrawal->id]); // Tandai sedang diproses
        });
        
        // --- KIRIM EMAIL SETELAH TRANSAKSI SUKSES ---
        if ($newWithdrawal) {
            try {
                // Ambil email admin dari Setting (atau hardcode jika belum ada settingnya)
                // Pastikan Anda sudah punya setting key 'admin_email' di database
                $adminEmail = Setting::getValue('admin_email'); 
                
                // Jika tidak ada di setting, bisa fallback ke email manual (opsional)
                $adminEmail = $adminEmail ?: 'admin@koperasiangkasa.com'; 

                if ($adminEmail) {
                    Mail::to($adminEmail)->send(new WithdrawalRequestNotification($newWithdrawal, $driver));
                }
            } catch (\Exception $e) {
                // Jangan gagalkan request driver hanya karena email gagal terkirim
                // Cukup catat di log
                \Illuminate\Support\Facades\Log::error('Gagal kirim email notifikasi withdrawal: ' . $e->getMessage());
            }
        }

        // Lonceng panel admin — berdampingan dengan email di atas, dengan
        // jaminan kegagalan yang sama: tidak boleh menggagalkan pengajuan.
        app(AdminNotifier::class)->notify(
            AdminNotification::TYPE_WITHDRAWAL,
            'Pencairan dana menunggu persetujuan',
            $driver->name . ' mengajukan Rp ' . number_format((float) $newWithdrawal->amount, 0, ',', '.') . '.',
            '#withdrawals',
            'warning',
            $newWithdrawal
        );

        // --- NOTIFIKASI WHATSAPP ---
        try {
            $waToken = Setting::getValue('wa_token');
            $adminWa = Setting::getValue('admin_wa_number');

            if ($waToken && $adminWa && $newWithdrawal) {
                
                $driverName = $driver->name;
                $amountRp = number_format($amountToWithdraw, 0, ',', '.');
                $time = now()->format('d M Y H:i');
                $bank = $driver->driverProfile->bank_name ?? '-';
                $rek = $driver->driverProfile->account_number ?? '-';

                $message = "*PENCAIRAN DANA BARU*\n\n"
                    . "Halo Admin, ada pengajuan baru:\n"
                    . "👤 *Driver:* $driverName\n"
                    . "💰 *Jumlah:* Rp $amountRp\n"
                    . "🏦 *Bank:* $bank ($rek)\n"
                    . "🕒 *Waktu:* $time\n\n"
                    . "Mohon segera cek dashboard admin untuk memproses.";

                // Panggil Service
                \App\Jobs\SendWhatsAppMessage::dispatch($adminWa, $message, $waToken);
            }
        } catch (\Exception $e) {
            // Error WA jangan sampai menggagalkan request driver
            \Illuminate\Support\Facades\Log::error("Gagal kirim WA: " . $e->getMessage());
        }
        // ---------------------------

        return response()->json(['message' => 'Permintaan pencairan berhasil dikirim.'], 201);
    }
    
    /**
     * Mengambil riwayat penarikan dana.
     */
    public function getWithdrawalHistory(Request $request)
    {
        $withdrawals = $request->user()->withdrawals()
            ->orderBy('requested_at', 'desc')
            ->get()
            ->map(function ($withdrawal) {
                 if ($withdrawal->proof_image) {
                     $withdrawal->proof_image_url = asset('storage/' . $withdrawal->proof_image);
                 } else {
                     $withdrawal->proof_image_url = null;
                 }
                 return $withdrawal;
            });
        return response()->json($withdrawals);
    }

    /**
     * Mengambil riwayat perjalanan (transaksi).
     */
    public function getTripHistory(Request $request)
    {
        $query = Transaction::whereHas('booking', function ($q) use ($request) {
            $q->where('driver_id', $request->user()->id);
        })->with('booking.zoneTo:id,name');

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }
        
        // Batasi payload mobile: 200 transaksi terbaru. Data lebih lama tetap bisa
        // diambil lewat filter date_from/date_to di atas. (Tetap array datar →
        // tidak memutus parsing di app.)
        $history = $query->orderBy('created_at', 'desc')->limit(200)->get();

        // Aturan kelayakan sanggahan dievaluasi DI SERVER lalu dikirim sebagai
        // flag, bukan disalin jadi kondisi if di Dart. Kalau aplikasi ikut
        // menghitung sendiri, suatu saat aturannya akan berbeda dari yang
        // ditegakkan endpoint dan supir melihat tombol yang selalu gagal.
        $bisa = Transaction::bisaDisanggah()
            ->whereIn('id', $history->pluck('id'))
            ->pluck('id')
            ->flip();

        $history->each(function ($t) use ($bisa) {
            $t->can_dispute = $bisa->has($t->id);
        });

        return response()->json($history);
    }

    /**
     * Update Informasi Rekening Bank Driver
     */
    public function updateBankDetails(Request $request)
    {
        $validated = $request->validate([
            'account_number' => 'required|string|max:50',
        ]);

        $user = $request->user();
        
        // Update atau Create profile jika belum ada
        $user->driverProfile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'bank_name' => 'Bank BTN',
                'account_number' => $validated['account_number']
            ]
        );

        return response()->json([
            'message' => 'Informasi rekening BTN berhasil disimpan.',
            'data' => $user->load('driverProfile')
        ]);
    }

    /**
     * Titik pusat & radius area bandara untuk peta di beranda supir.
     *
     * Dipisah dari updateLocation() yang punya belasan titik keluar berbeda —
     * peta hanya butuh geofence-nya, dan datanya nyaris tak pernah berubah
     * sehingga aman diambil sekali lalu di-cache aplikasi.
     * Bentuk `base` sengaja disamakan dengan endpoint peta CSO & admin.
     */
    public function getAirportArea()
    {
        return response()->json([
            'base' => [
                'latitude'  => Setting::airportLatitude(),
                'longitude' => Setting::airportLongitude(),
                'radius_km' => Setting::airportRadiusKm(),
            ],
        ]);
    }

    public function updateLocation(Request $request)
    {
        // ... (Validasi & Cek Queue tetap sama) ...
        $request->validate([
            // Rentang WAJIB dibatasi: tanpa ini, fix cacat (0,0) diterima apa
            // adanya dan menghasilkan jarak ~12.000 km — supir langsung
            // dinyatakan di luar area dan jam tenggangnya mulai berjalan.
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            // Akurasi (meter) dari perangkat. Opsional supaya versi aplikasi
            // lama tetap jalan — bila tidak dikirim, fix dianggap layak.
            'accuracy'  => 'nullable|numeric|min:0',
            // Catatan: `is_mocked` (Position.isMocked dari Android) SENGAJA
            // tidak divalidasi di sini — dibaca longgar lewat bacaFlagMock(),
            // lihat alasannya di sana. Flag ini juga TIDAK bisa diandalkan
            // sendirian: APK modifikasi tinggal tidak mengirimnya, makanya
            // deteksi teleport di server melengkapinya.
        ]);

        // (0,0) bukan lokasi nyata — itu sentinel "belum ada fix" yang memang
        // muncul di sistem ini (lihat catatan pre-fill rotasi di
        // CsoApiController::readyQueueQuery). Kalau diterima, jaraknya ke
        // bandara ~12.000 km dan supir langsung dinyatakan di luar area.
        // Ditolak SEBELUM disimpan supaya tidak ikut menyegarkan bukti
        // kehadiran (location_updated_at) juga.
        if ((float) $request->latitude === 0.0 && (float) $request->longitude === 0.0) {
            return response()->json(['message' => 'Koordinat tidak valid (0,0).'], 422);
        }

        $user = $request->user();
        $profile = $user->driverProfile;

        // GERBANG ANTI FAKE GPS — WAJIB sebelum baris penyimpanan mana pun.
        // Fix yang dicurigai palsu tidak boleh menyegarkan `location_updated_at`
        // (itulah bukti kehadiran yang dibaca CSO & penyapu antrian), tidak
        // boleh masuk breadcrumb rute, dan tidak boleh dipakai memutuskan
        // geofence. Ditolak di sini, sebelum menyentuh apa pun.
        $alasanPalsu = $this->periksaLokasiPalsu(
            $profile,
            (float) $request->latitude,
            (float) $request->longitude,
            $this->bacaFlagMock($request)
        );

        if ($alasanPalsu !== null) {
            $this->hukumLokasiPalsu($profile, $user->id, $alasanPalsu);

            return response()->json([
                'message' => $alasanPalsu === 'mock_provider'
                    ? 'Lokasi palsu terdeteksi. Matikan aplikasi fake GPS, lalu masuk antrian lagi secara manual.'
                    : 'Perpindahan lokasi tidak wajar — data lokasi ini diabaikan.',
                'spoof_detected' => true,
                'reason'         => $alasanPalsu,
            ], 422);
        }

        // Selalu simpan lokasi terakhir di profil — termasuk saat driver sedang
        // OnTrip (tidak ada di tabel antrian). Inilah yang membuat peta CSO bisa
        // tetap menampilkan & melacak pergerakan driver yang sedang mengantar.
        if ($profile) {
            $profile->update([
                'latitude'            => $request->latitude,
                'longitude'           => $request->longitude,
                'location_updated_at' => now(),
            ]);
        }

        // Rekam jejak pergerakan (breadcrumb) untuk gambaran RUTE di panel admin.
        // Throttle per jarak (~20 m) agar trail rapi & hemat storage.
        if ($profile) {
            $movedFar = $profile->track_lat === null
                || $this->calculateDistance(
                        (float) $profile->track_lat, (float) $profile->track_lng,
                        $request->latitude, $request->longitude
                   ) >= 0.02;
            if ($movedFar) {
                \App\Models\DriverLocationLog::create([
                    'user_id'     => $user->id,
                    'latitude'    => $request->latitude,
                    'longitude'   => $request->longitude,
                    'recorded_at' => now(),
                ]);
                $profile->update([
                    'track_lat' => $request->latitude,
                    'track_lng' => $request->longitude,
                ]);
            }
        }

        // 1. Cek Antrian
        $isInQueue = DriverQueue::where('user_id', $user->id)->exists();
        
        // 2. Cek Jarak
        $airportLat = Setting::airportLatitude();
        $airportLng = Setting::airportLongitude();
        $distance = $this->calculateDistance($airportLat, $airportLng, $request->latitude, $request->longitude);
        $radius = Setting::airportRadiusKm();
        $prevInArea = $profile ? $profile->last_in_area : null; // null = belum ada baseline

        // Fix yang akurasinya buruk TIDAK dipakai untuk memutuskan geofence:
        // posisinya sudah terlanjur disimpan di atas (peta CSO tetap hidup dan
        // supir tetap terhitung "mengirim kabar"), tapi status di dalam/luar
        // area dipertahankan apa adanya sampai datang fix yang layak.
        $accuracy = $request->filled('accuracy') ? (float) $request->accuracy : null;
        $batasAkurasi = (float) config('taksi.driver_queue.poor_accuracy_meters');
        $fixMeragukan = $accuracy !== null && $batasAkurasi > 0 && $accuracy > $batasAkurasi;

        if ($fixMeragukan && $prevInArea !== null) {
            $inArea = (bool) $prevInArea;
        } else {
            // Histeresis: sekali di DALAM, baru dianggap keluar setelah melewati
            // radius + sabuk. Mencegah supir yang parkir di tepi lingkaran
            // bolak-balik "masuk-keluar" hanya karena jitter GPS.
            $sabuk = (float) config('taksi.driver_queue.geofence_hysteresis_km');
            $ambang = $prevInArea === true ? $radius + $sabuk : $radius;
            $inArea = ($distance <= $ambang);
        }

        // Tenggang "di luar area" (detik) dari Pengaturan admin. 0 = auto-keluar
        // antrian dinonaktifkan: supir tetap ditandai di luar area & tercatat di
        // log, tapi antriannya tidak pernah hangus sendiri.
        $graceMinutes = Setting::outOfAreaGraceMinutes();
        $graceSeconds = $graceMinutes * 60;

        // Gerbang jam operasi: app akan mematikan layanan lokasi bila false
        // & tidak sedang mengantar (lihat background_service.dart).
        $trackingOpen = Setting::isWithinOperatingHours();

        // Hitung berapa kali supir KELUAR-MASUK area bandara (independen dari
        // status antrian) — bandingkan dengan state in_area sebelumnya.
        if ($profile) {
            $prev = $prevInArea; // null (baseline) | bool — dibaca sebelum histeresis
            if ($prev === null) {
                $profile->update(['last_in_area' => $inArea]);
            } elseif ((bool) $prev !== $inArea) {
                if ($inArea) {
                    $profile->increment('airport_entries');
                    $this->logActivity($user->id, 'AIRPORT_ENTER', 'Masuk area bandara');
                } else {
                    $profile->increment('airport_exits');
                    $this->logActivity($user->id, 'AIRPORT_EXIT', 'Keluar area bandara');
                }
                $profile->update(['last_in_area' => $inArea]);
            }
        }

        // 3. Logika Auto-Join & Grace Period
        // Global Warning: Jika diluar area saat standby -> Cek Grace Period
        $remainingTime = null;
        
        if ($profile->status === 'standby' && !$inArea && $graceSeconds === 0) {
             // Auto-keluar dimatikan admin. Penanda TETAP diisi — CSO memakai
             // `out_of_area_since` untuk menyaring supir yang tak boleh dapat
             // order (lihat CsoApiController::readyQueueQuery) — tapi selalu
             // disegarkan ke SEKARANG supaya jam tenggang tidak diam-diam
             // menumpuk. Kalau admin menyalakan lagi auto-keluar, hitung mundur
             // mulai dari nol, bukan dari saat supir keluar area berjam-jam lalu.
             if (!$profile->out_of_area_since) {
                 $this->logActivity($user->id, 'AREA_LEAVE_WARNING', 'Keluar Area (auto-keluar nonaktif)');
             }
             $profile->update(['out_of_area_since' => now()]);
        } elseif ($profile->status === 'standby' && !$inArea) {
             // Jika baru saja keluar area (out_of_area_since masih null)
             if (!$profile->out_of_area_since) {
                 $profile->update(['out_of_area_since' => now()]);
                 // LOG ACTIVITY
                 $this->logActivity($user->id, 'AREA_LEAVE_WARNING', 'Keluar Area (Peringatan dimulai)');
                 $remainingTime = $graceSeconds > 0 ? $graceSeconds : null;
             } elseif ($graceSeconds > 0) {
                 // Cek durasi
                 $outSince = \Carbon\Carbon::parse($profile->out_of_area_since);
                 
                 // Gunakan timestamp agar hasil pasti integer dan positif
                 $diffInSeconds = now()->timestamp - $outSince->timestamp;
                 
                 if ($diffInSeconds >= $graceSeconds) {
                     // AUTO KICK
                     DriverQueue::where('user_id', $user->id)->delete();
                     $profile->update([
                         'status' => 'offline',
                         'out_of_area_since' => null,
                         'auto_join_blocked' => true // BLOCK AUTO JOIN
                     ]);

                     // LOG ACTIVITY
                     $this->logActivity($user->id, 'QUEUE_LEAVE_AUTO', "Dikeluarkan dari antrian (Timeout: >{$graceMinutes} menit diluar area)");
                     
                     return response()->json([
                         'status' => 'offline',
                         'in_area' => false,
                         'tracking_open' => $trackingOpen,
                         'grace_enabled' => true,
                         'message' => "Antrian hangus karena diluar area lebih dari {$graceMinutes} menit."
                     ]);
                 }
                 
                 $remainingTime = (int) ($graceSeconds - $diffInSeconds);
             }
        } elseif ($profile->status === 'standby' && $inArea) {
             // Jika kembali ke area -> Reset Grace Period
             if ($profile->out_of_area_since) {
                 $profile->update(['out_of_area_since' => null]);
                 // LOG ACTIVITY
                 $this->logActivity($user->id, 'AREA_RETURN', 'Kembali ke Area (Peringatan dihapus)');
             }
        }

        // Logic Auto-Join (Jika Offline & Masuk Area) - Tetap sama
        if ($inArea && $profile->status === 'offline') {

            // GERBANG ORDER AKTIF — justru DI SINI bug-nya paling terasa.
            // Supir yang baru dapat order berstatus 'offline' menurut profil
            // (lihat gerbangOrderAktif untuk rantai lengkapnya), sehingga
            // tanpa penjaga ini auto-join mengembalikannya ke antrian sambil
            // ordernya masih menggantung — tanpa ia melakukan apa pun.
            if ($pesanOrder = $this->gerbangOrderAktif($user)) {
                return response()->json([
                    'status'           => 'offline',
                    'in_area'          => true,
                    'tracking_open'    => $trackingOpen,
                    'has_active_order' => true,
                    'message'          => $pesanOrder,
                ]);
            }

            // CEK APAKAH DIBLOKIR?
            if ($profile->auto_join_blocked) {
                return response()->json([
                    'status' => 'offline',
                    'in_area' => true,
                    'tracking_open' => $trackingOpen,
                    'message' => 'Anda harus menekan tombol "Masuk Antrian" secara manual.'
                ]);
            }

            // GERBANG UTANG juga di pintu OTOMATIS. Kalau hanya join manual
            // yang dijaga, supir berutang tinggal berjalan masuk area dan
            // auto-join menariknya kembali ke antrian — pembatasannya jadi
            // hiasan belaka.
            if ($pesanUtang = $this->gerbangUtang($user)) {
                return response()->json([
                    'status'         => 'offline',
                    'in_area'        => true,
                    'tracking_open'  => $trackingOpen,
                    'debt_blocked'   => true,
                    'message'        => $pesanUtang,
                ]);
            }

            if (!$isInQueue) {
                // Skenario Normal: Masuk Antrian Baru.
                // Urutan memakai rumus rotasi yang SAMA dengan join manual —
                // lihat queueSortOrderFor().
                DriverQueue::create([
                    'user_id' => $user->id,
                    'sort_order' => $this->queueSortOrderFor($profile),
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude
                ]);
                
                // Update Status Profil
                $profile->update([
                    'status' => 'standby',
                    'last_queue_date' => now()->toDateString(),
                    'out_of_area_since' => null // Pastikan bersih
                ]);

                // LOG ACTIVITY
                $this->logActivity($user->id, 'QUEUE_JOIN_AUTO', 'Masuk Antrian Otomatis (Masuk Area)');

                return response()->json([
                    'status' => 'standby',
                    'message' => 'Anda memasuki area bandara (Auto-Queue).',
                    'in_area' => true,
                    'tracking_open' => $trackingOpen
                ]);
            } else {
                // Skenario Repair
                 $profile->update([
                    'status' => 'standby',
                    'out_of_area_since' => null
                ]);
                
                // LOG ACTIVITY
                $this->logActivity($user->id, 'QUEUE_JOIN_REPAIR', 'Masuk Antrian (Repair/Recovery)');

                return response()->json([
                    'status' => 'standby',
                    'message' => 'Status antrian dipulihkan (Auto-Repair).',
                    'in_area' => true,
                    'tracking_open' => $trackingOpen
                ]);
            }

        }

        // 4. Update Koordinat (Jika sudah di antrian)
        if ($isInQueue) {
             DriverQueue::where('user_id', $user->id)->update([
                'latitude' => $request->latitude,
                'longitude' => $request->longitude
            ]);
        }
        
        // 5. Tambahkan info line_number agar Notifikasi HP bisa baca
        $lineNumber = $profile->line_number;

        return response()->json([
            'status' => $profile->status,
            'in_area' => $inArea,
            'remaining_time' => $remainingTime,
            // false = admin mematikan auto-keluar antrian; app menampilkan
            // status "aman" alih-alih hitung mundur kosong.
            'grace_enabled' => $graceSeconds > 0,
            'line_number' => $lineNumber,
            'tracking_open' => $trackingOpen,
        ]);
    }

    
    public function startBooking(Request $request, Booking $booking)
    {
        if ($request->user()->id !== $booking->driver_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Hanya order yang belum dimulai & belum selesai yang boleh di-Start.
        // (Sebelumnya tanpa cek status: order Completed/Cancelled pun bisa
        //  dipaksa menjadi OnTrip.)
        if (in_array($booking->status, ['OnTrip', 'Completed', 'Cancelled'])) {
            return response()->json([
                'message' => 'Order ini tidak bisa dimulai (status saat ini: ' . $booking->status . ').',
            ], 422);
        }

        $booking->update(['status' => 'OnTrip']);

        // Update status profil jadi 'ontrip' (biar UI driver tahu dia sedang sibuk)
        $request->user()->driverProfile()->update(['status' => 'ontrip']);

        // Kembalikan profile terbaru agar UI driver terupdate
        return $this->getProfile($request);
    }

    /**
     * Fitur Ganti Password Driver
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|string|min:6|confirmed', // Butuh field new_password_confirmation
        ]);

        $user = $request->user();

        // 1. Cek Password Lama
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Password saat ini salah.'], 422);
        }

        // 2. Update Password Baru
        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        return response()->json(['message' => 'Password berhasil diubah.']);
    }

    /**
     * Update Profil Driver (Biodata & Kendaraan)
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        // 1. Validasi Input
        $validated = $request->validate([
            // Validasi Tabel Users
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|max:255|unique:users,email,' . $user->id,
            'phone_number' => 'required|string|max:20|unique:users,phone_number,' . $user->id,
            'username'     => 'required|string|max:50|unique:users,username,' . $user->id,
            
            // Validasi Tabel DriverProfiles
            'car_model'    => 'required|string|max:50',
            'plate_number' => 'required|string|max:20',
        ]);

        DB::transaction(function () use ($user, $validated) {
            // 2. Update Tabel Users
            $user->update([
                'name'         => $validated['name'],
                'email'        => $validated['email'],
                'phone_number' => $validated['phone_number'],
                'username'     => $validated['username'],
            ]);

            // 3. Update Tabel DriverProfiles
            $user->driverProfile()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'car_model'    => $validated['car_model'],
                    'plate_number' => $validated['plate_number']
                ]
            );
        });

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'data'    => $user->load('driverProfile')
        ]);
    }

    public function updateFcmToken(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        $request->user()->update([
            'fcm_token' => $request->fcm_token
        ]);

        return response()->json(['message' => 'FCM Token updated successfully']);
    }
    private function logActivity($userId, $type, $desc)
    {
        try {
            \App\Models\DriverActivity::create([
                'user_id' => $userId,
                'activity_type' => $type,
                'description' => $desc
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Activiy Log Error: ' . $e->getMessage());
        }
    }

    // =====================================================================
    // ===  SENGKETA METODE PEMBAYARAN                                    ===
    // =====================================================================

    /**
     * Supir menyanggah bahwa ia menerima uang tunai atas satu order.
     *
     * CSO memilih sendiri metode pembayaran saat membuat order. Bila ia
     * menerima tunai dari penumpang lalu mencatatnya 'CashDriver', uangnya ada
     * di tangan CSO tetapi komisinya ditagihkan kepada supir. Server tidak
     * mungkin tahu uang fisik berpindah ke siapa — jadi yang diberikan di sini
     * bukan pencegahan, melainkan HAK SUARA: supir bisa membantah, dan
     * bantahannya jadi antrean keputusan admin, bukan hilang jadi keluhan lisan.
     */
    public function disputeMethod(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'note' => 'required|string|min:5|max:500',
        ], [
            'note.required' => 'Tulis alasan sanggahan agar admin tahu apa yang terjadi.',
            'note.min'      => 'Alasan terlalu pendek — jelaskan singkat apa yang sebenarnya terjadi.',
        ]);

        $driver = $request->user();

        // Kepemilikan diperiksa lewat booking — route model binding tidak
        // memeriksa apa pun (pelajaran yang sama dengan depositDetail).
        $transaction->loadMissing('booking');
        if (!$transaction->booking || (int) $transaction->booking->driver_id !== (int) $driver->id) {
            return response()->json(['message' => 'Transaksi ini bukan milik Anda.'], 403);
        }

        // Syarat kelayakan dipusatkan di satu scope supaya aturan yang dilihat
        // aplikasi (tombol muncul/tidak) dan yang ditegakkan server tidak bisa
        // berbeda. Alasan tiap syarat ada di Transaction::scopeBisaDisanggah().
        $layak = Transaction::bisaDisanggah()->whereKey($transaction->id)->exists();
        if (!$layak) {
            return response()->json([
                'message' => $transaction->method_dispute_status !== null
                    ? 'Transaksi ini sudah pernah disanggah.'
                    : 'Transaksi ini tidak bisa disanggah (bukan tunai ke supir, atau sudah diselesaikan).',
            ], 422);
        }

        $transaction->update([
            'method_dispute_status' => 'Open',
            'method_dispute_note'   => $validated['note'],
            'method_disputed_at'    => now(),
        ]);

        $this->logActivity(
            $driver->id,
            'PAYMENT_DISPUTE',
            'Menyanggah metode pembayaran order #' . $transaction->booking_id
        );

        app(AdminNotifier::class)->notify(
            AdminNotification::TYPE_METHOD_DISPUTE,
            'Sengketa metode pembayaran dibuka',
            $driver->name . ' menyanggah metode bayar order #' . $transaction->booking_id
                . ' (Rp ' . number_format((float) $transaction->amount, 0, ',', '.') . ').',
            '#method-disputes',
            'danger',
            $transaction
        );

        // Kabari admin — sengketa yang tidak pernah dibaca sama saja dengan
        // tidak ada saluran sengketa.
        try {
            $waToken = Setting::getValue('wa_token');
            $adminWa = Setting::getValue('admin_wa_number');
            if ($waToken && $adminWa) {
                $rp = number_format((float) $transaction->amount, 0, ',', '.');
                \App\Jobs\SendWhatsAppMessage::dispatch(
                    $adminWa,
                    "*SANGGAHAN PEMBAYARAN*\n\nSupir *{$driver->name}* menyanggah order #{$transaction->booking_id} (Rp {$rp}) yang tercatat sebagai Tunai ke Supir.\n\nAlasan: {$validated['note']}\n\nSilakan periksa di menu Sengketa Pembayaran.",
                    $waToken
                );
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gagal kirim WA sanggahan: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Sanggahan dikirim. Admin akan memeriksa dan memutuskan.',
            'data'    => $transaction->fresh(),
        ], 201);
    }

    // =====================================================================
    // ===  SETORAN TUNAI SUPIR -> ADMIN (pelunasan utang komisi)         ===
    // =====================================================================

    /**
     * Fee yang terutang atas SATU transaksi tunai-ke-supir.
     *
     * Aturannya sama persis dengan blok HUTANG di hitungSaldo(): order via
     * zona kena persentase komisi, order manual (tanpa zona) kena fee flat per
     * trip. Ditulis sekali di sini lalu dipakai baik untuk rekap maupun untuk
     * mengunci nominal setoran — kalau bercabang, angka yang dilihat supir bisa
     * berbeda dari angka yang ditagih.
     */
    private function feeTerutang(Transaction $t, float $rate, int $manualFlat): float
    {
        // Rumusnya kini tinggal satu salinan di CommissionCalculator, supaya
        // Laporan Pendapatan admin tidak bisa lagi berbeda angka dengan tagihan
        // setoran supir. $rate/$manualFlat tetap diteruskan apa adanya — sudah
        // dibaca pemanggil di luar loop — jadi hasilnya identik seperti dulu.
        return CommissionCalculator::compute($t, $rate, $manualFlat);
    }

    /**
     * Rekap utang yang BELUM disetor, dikelompokkan per tanggal.
     *
     * Inilah daftar yang dicentang supir di aplikasi — bentuknya sengaja
     * dibuat sama dengan rekap setoran CSO.
     */
    public function depositOutstanding(Request $request)
    {
        $driver     = $request->user();
        $rate       = (float) Setting::getValue('commission_rate') ?: 0.2;
        $manualFlat = (int) (Setting::getValue('manual_fee_flat') ?: 10000);

        // Fee per baris bergantung pada ada/tidaknya zona, jadi tidak bisa
        // dijumlahkan murni di SQL seperti rekap CSO. Yang ditarik hanya kolom
        // seperlunya, dan hanya transaksi yang memang masih berutang.
        $rows = Transaction::cashDriverBelumLunas($driver->id)
            ->with('booking:id,zone_id')
            ->get(['id', 'amount', 'booking_id', 'created_at']);

        $perTanggal = [];
        foreach ($rows as $t) {
            $tgl = $t->created_at->toDateString();
            $perTanggal[$tgl] ??= ['date' => $tgl, 'total' => 0.0, 'count' => 0];
            $perTanggal[$tgl]['total'] += $this->feeTerutang($t, $rate, $manualFlat);
            $perTanggal[$tgl]['count']++;
        }

        krsort($perTanggal); // terbaru dulu
        $days = array_values(array_map(
            fn ($d) => ['date' => $d['date'], 'total' => round($d['total']), 'count' => $d['count']],
            $perTanggal
        ));

        return response()->json([
            'days'           => $days,
            'grand_total'    => (float) array_sum(array_column($days, 'total')),
            'grand_count'    => (int) array_sum(array_column($days, 'count')),
            'debt_limit'     => Setting::maxDriverDebt(),
            'commission_rate' => $rate,
            'manual_fee_flat' => $manualFlat,
        ]);
    }

    /**
     * Buat setoran atas satu/beberapa tanggal.
     *
     * Nominal TIDAK diterima dari klien: server mengunci sendiri transaksi yang
     * memenuhi syarat lalu menjumlahkan fee-nya. Inilah yang membuat dua
     * perangkat yang menekan "Setor" bersamaan tidak bisa menyetor utang yang
     * sama dua kali — pola yang sama dengan CsoApiController::storeDeposit().
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

        $driver     = $request->user();
        $dates      = array_values(array_unique($validated['dates']));
        $rate       = (float) Setting::getValue('commission_rate') ?: 0.2;
        $manualFlat = (int) (Setting::getValue('manual_fee_flat') ?: 10000);

        // Foto disimpan SEBELUM transaksi DB supaya kegagalan upload tidak
        // menyisakan setoran setengah jadi.
        $proofPath = $request->hasFile('proof_image')
            ? $request->file('proof_image')->store('deposit_proofs', 'public')
            : null;

        $hasil = DB::transaction(function () use ($driver, $dates, $validated, $proofPath, $rate, $manualFlat) {
            $placeholders = implode(',', array_fill(0, count($dates), '?'));

            $rows = Transaction::cashDriverBelumLunas($driver->id)
                ->whereRaw("DATE(transactions.created_at) IN ($placeholders)", $dates)
                ->with('booking:id,zone_id')
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return ['error' => 'Tidak ada utang yang perlu disetor pada tanggal tersebut.'];
            }

            $total = 0.0;
            foreach ($rows as $t) {
                $total += $this->feeTerutang($t, $rate, $manualFlat);
            }

            // period_dates diambil dari baris yang BENAR-BENAR terkunci, bukan
            // dari permintaan klien — tanggal kosong tidak ikut tercatat.
            $tanggalNyata = $rows
                ->map(fn ($t) => $t->created_at->toDateString())
                ->unique()->sort()->values()->all();

            $deposit = \App\Models\DriverDeposit::create([
                'driver_id'          => $driver->id,
                'amount'             => round($total),
                'status'             => 'Pending',
                'period_dates'       => $tanggalNyata,
                'transactions_count' => $rows->count(),
                'note'               => $validated['note'] ?? null,
                'proof_image'        => $proofPath,
                'submitted_at'       => now(),
            ]);

            // 'Processing' memakai kolom yang SAMA dengan pencairan: sejak
            // detik ini transaksinya tidak bisa lagi ikut pencairan maupun
            // setoran lain.
            Transaction::whereIn('id', $rows->pluck('id'))->update([
                'payout_status'     => 'Processing',
                'driver_deposit_id' => $deposit->id,
            ]);

            return ['deposit' => $deposit];
        });

        if (isset($hasil['error'])) {
            // Utangnya tidak jadi terkunci -> fotonya pun tidak ada gunanya.
            if ($proofPath) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($proofPath);
            }
            return response()->json(['message' => $hasil['error']], 422);
        }

        $deposit = $hasil['deposit'];

        // Stempel dibakar SETELAH setoran terbentuk: nominal & nomornya baru
        // pasti di titik ini, sehingga satu foto tidak bisa dipakai ulang untuk
        // setoran lain. Pola & alasan sama dengan setoran CSO.
        if ($proofPath) {
            app(\App\Services\ImageWatermark::class)->stamp($proofPath, [
                'BUKTI SETORAN SUPIR #' . $deposit->id,
                'Supir    : ' . $driver->name,
                'Nominal  : Rp ' . number_format((float) $deposit->amount, 0, ',', '.'),
                'Tanggal  : ' . implode(', ', $deposit->period_dates ?? []),
                'Transaksi: ' . $deposit->transactions_count . ' order tunai',
                'Diunggah : ' . now()->format('d/m/Y H:i') . ' WIB',
            ]);
        }

        $this->logActivity($driver->id, 'DEPOSIT_SUBMIT', 'Mengajukan setoran tunai Rp ' . number_format((float) $deposit->amount, 0, ',', '.'));

        app(AdminNotifier::class)->notify(
            AdminNotification::TYPE_DRIVER_DEPOSIT,
            'Setoran tunai supir menunggu verifikasi',
            $driver->name . ' menyetor Rp ' . number_format((float) $deposit->amount, 0, ',', '.')
                . ' (' . $deposit->transactions_count . ' transaksi).',
            '#driver-deposits',
            'info',
            $deposit
        );

        // Tanda terima ke supir (alasan sama seperti pada setoran CSO: uang
        // fisik sudah berpindah, penyetor berhak punya bukti di HP-nya).
        app(PushNotifier::class)->toUser(
            $driver,
            'Setoran terkirim',
            'Setoran Rp ' . number_format((float) $deposit->amount, 0, ',', '.')
                . ' untuk ' . count($deposit->period_dates ?? []) . ' tanggal sudah kami terima '
                . 'dan sedang menunggu verifikasi admin.',
            ['type' => 'deposit', 'role' => 'driver', 'deposit_id' => (string) $deposit->id]
        );

        \App\Services\WhatsAppService::toAdmin(
            "*SETORAN SUPIR MASUK*\n\n"
            . "{$driver->name} mengajukan setoran tunai.\n\n"
            . '💰 Rp ' . number_format((float) $deposit->amount, 0, ',', '.') . "\n"
            . '🧾 ' . $deposit->transactions_count . ' transaksi · '
            . count($deposit->period_dates ?? []) . " tanggal\n"
            . '📅 ' . now()->format('d M Y H:i') . "\n\n"
            . 'Menunggu verifikasi di panel admin.'
        );

        return response()->json([
            'message' => 'Setoran diajukan, menunggu verifikasi admin.',
            'data'    => $deposit,
        ], 201);
    }

    /** Riwayat setoran milik supir yang sedang login. */
    public function depositHistory(Request $request)
    {
        return response()->json(
            \App\Models\DriverDeposit::where('driver_id', $request->user()->id)
                ->orderByDesc('submitted_at')
                ->paginate(20)
        );
    }

    /** Detail satu setoran + transaksi yang tercakup di dalamnya. */
    public function depositDetail(Request $request, \App\Models\DriverDeposit $deposit)
    {
        // Route model binding TIDAK memeriksa kepemilikan — tanpa baris ini,
        // supir mana pun bisa membaca setoran supir lain hanya dengan menebak
        // id. Pelajaran yang sama sudah dipetik di CsoApiController.
        if ((int) $deposit->driver_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Setoran ini bukan milik Anda.'], 403);
        }

        $deposit->load(['transactions.booking.zoneTo:id,name']);

        return response()->json($deposit);
    }
}