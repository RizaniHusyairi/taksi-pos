<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DriverDeposit;
use App\Models\DriverProfile;
use App\Models\DriverQueue;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ambang utang supir + jalur setoran tunai supir ke admin.
 *
 * Yang dikunci di sini adalah dua janji yang gampang rusak diam-diam:
 *  1. Angka utang yang MEMBLOKIR supir harus sama persis dengan angka yang
 *     DITAMPILKAN di dompetnya. Kalau bercabang, supir diblokir oleh angka
 *     yang tidak pernah ia lihat.
 *  2. Satu transaksi tidak boleh bisa dilunasi lewat setoran DAN sekaligus
 *     dipotong lewat pencairan.
 */
class DriverDebtGateTest extends TestCase
{
    use RefreshDatabase;

    private const APT_LAT = -0.371975;
    private const APT_LNG = 117.257919;

    private function driver(): User
    {
        $user = User::create([
            'name' => 'Supir', 'username' => 'supir', 'email' => 's@x.test',
            'password' => bcrypt('x'), 'role' => 'driver', 'active' => true,
        ]);

        DriverProfile::create([
            'user_id'             => $user->id,
            'car_model'           => 'Avanza',
            'plate_number'        => 'KT 1234 AB',
            'line_number'         => 1,
            'status'              => 'offline',
            'account_number'      => '1234567890',
            'bank_name'           => 'Bank BTN',
            'latitude'            => self::APT_LAT,
            'longitude'           => self::APT_LNG,
            'location_updated_at' => now()->subSeconds(30),
            'last_in_area'        => true,
        ]);

        return $user;
    }

    private function zone(): Zone
    {
        return Zone::create(['name' => 'Kota', 'price' => 100000]);
    }

    /**
     * Satu order tunai-ke-supir yang sudah selesai → melahirkan utang komisi.
     * Dengan rate 20% dan tarif Rp 100.000, utangnya Rp 20.000 per order.
     */
    private function utangOrder(User $driver, int $harga = 100000, ?string $tanggal = null): Transaction
    {
        $booking = Booking::create([
            'cso_id'    => $driver->id,
            'driver_id' => $driver->id,
            'zone_id'   => $this->zone()->id,
            'price'     => $harga,
            'status'    => 'Completed',
        ]);

        $t = Transaction::create([
            'booking_id'    => $booking->id,
            'method'        => 'CashDriver',
            'amount'        => $harga,
            'payout_status' => 'Unpaid',
        ]);

        if ($tanggal) {
            // created_at dipakai untuk pengelompokan per tanggal di rekap.
            $t->forceFill(['created_at' => $tanggal . ' 10:00:00'])->save();
        }

        return $t->fresh();
    }

    private function setRate(float $rate = 0.2): void
    {
        Setting::updateOrCreate(['key' => 'commission_rate'], ['value' => $rate]);
        Setting::forgetMemo();
    }

    private function setBatas(int $rupiah): void
    {
        Setting::updateOrCreate(['key' => 'max_driver_debt'], ['value' => $rupiah]);
        Setting::forgetMemo();
    }

    // === Gerbang utang ====================================================

    public function test_batas_nol_berarti_pembatasan_mati(): void
    {
        $this->setRate();
        $this->setBatas(0);
        $driver = $this->driver();
        $this->utangOrder($driver); // utang 20.000

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/status', [
            'action' => 'join', 'latitude' => self::APT_LAT, 'longitude' => self::APT_LNG,
        ])->assertOk();

        $this->assertSame(1, DriverQueue::where('user_id', $driver->id)->count());
    }

    public function test_utang_di_bawah_batas_masih_boleh_masuk_antrian(): void
    {
        $this->setRate();
        $this->setBatas(50000);
        $driver = $this->driver();
        $this->utangOrder($driver); // utang 20.000 < 50.000

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/status', [
            'action' => 'join', 'latitude' => self::APT_LAT, 'longitude' => self::APT_LNG,
        ])->assertOk();
    }

    public function test_utang_melewati_batas_memblokir_join_manual(): void
    {
        $this->setRate();
        $this->setBatas(30000);
        $driver = $this->driver();
        $this->utangOrder($driver);
        $this->utangOrder($driver); // total utang 40.000 > 30.000

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/status', [
            'action' => 'join', 'latitude' => self::APT_LAT, 'longitude' => self::APT_LNG,
        ])
            ->assertStatus(422)
            ->assertJson(['debt_blocked' => true]);

        $this->assertSame(0, DriverQueue::where('user_id', $driver->id)->count());
    }

    /**
     * Kalau hanya join manual yang dijaga, supir berutang tinggal berjalan
     * masuk area dan auto-join menariknya kembali — pembatasannya jadi hiasan.
     */
    public function test_utang_melewati_batas_juga_memblokir_auto_join(): void
    {
        $this->setRate();
        $this->setBatas(30000);
        $driver = $this->driver();
        $this->utangOrder($driver);
        $this->utangOrder($driver);

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/location', [
            'latitude' => self::APT_LAT, 'longitude' => self::APT_LNG,
        ])
            ->assertOk()
            ->assertJson(['debt_blocked' => true, 'status' => 'offline']);

        $this->assertSame(0, DriverQueue::where('user_id', $driver->id)->count());
    }

    /**
     * Janji terpenting: angka yang memblokir = angka yang ditampilkan.
     * Kalau rumusnya bercabang, supir diblokir oleh angka yang tidak pernah
     * ia lihat di dompetnya.
     */
    public function test_angka_yang_memblokir_sama_dengan_angka_di_dompet(): void
    {
        $this->setRate();
        $this->setBatas(30000);
        $driver = $this->driver();
        $this->utangOrder($driver);
        $this->utangOrder($driver);

        Sanctum::actingAs($driver);
        $dompet = $this->getJson('/api/driver/balance')->assertOk()->json();

        $this->assertSame(40000, (int) $dompet['debt_pending']);
        $this->assertSame(30000, (int) $dompet['debt_limit']);
        $this->assertTrue($dompet['debt_blocked']);

        // Pesan penolakan harus menyebut angka yang sama persis.
        $pesan = $this->postJson('/api/driver/status', [
            'action' => 'join', 'latitude' => self::APT_LAT, 'longitude' => self::APT_LNG,
        ])->json('message');

        $this->assertStringContainsString('40.000', $pesan);
        $this->assertStringContainsString('30.000', $pesan);
    }

    public function test_dompet_menampilkan_sisa_jatah_sebelum_terblokir(): void
    {
        $this->setRate();
        $this->setBatas(50000);
        $driver = $this->driver();
        $this->utangOrder($driver); // 20.000

        Sanctum::actingAs($driver);
        $dompet = $this->getJson('/api/driver/balance')->assertOk()->json();

        $this->assertSame(30000, $dompet['debt_remaining']);
        $this->assertFalse($dompet['debt_blocked']);
    }

    // === Setoran tunai supir ==============================================

    public function test_rekap_utang_dikelompokkan_per_tanggal(): void
    {
        $this->setRate();
        $driver = $this->driver();
        $this->utangOrder($driver, 100000, '2026-08-20');
        $this->utangOrder($driver, 200000, '2026-08-20');
        $this->utangOrder($driver, 100000, '2026-08-21');

        Sanctum::actingAs($driver);
        $rekap = $this->getJson('/api/driver/deposits/outstanding')->assertOk()->json();

        $this->assertSame(80000.0, (float) $rekap['grand_total']); // 20k+40k+20k
        $this->assertSame(3, $rekap['grand_count']);
        $this->assertCount(2, $rekap['days']);
        // Terbaru dulu.
        $this->assertSame('2026-08-21', $rekap['days'][0]['date']);
        $this->assertSame(60000, (int) $rekap['days'][1]['total']);
    }

    /** Order manual (tanpa zona) kena fee flat, bukan persentase. */
    public function test_order_manual_kena_fee_flat(): void
    {
        $this->setRate();
        Setting::updateOrCreate(['key' => 'manual_fee_flat'], ['value' => 10000]);
        Setting::forgetMemo();

        $driver = $this->driver();
        $booking = Booking::create([
            'cso_id' => $driver->id, 'driver_id' => $driver->id, 'zone_id' => null,
            'manual_destination' => 'Samarinda', 'price' => 500000, 'status' => 'Completed',
        ]);
        Transaction::create([
            'booking_id' => $booking->id, 'method' => 'CashDriver',
            'amount' => 500000, 'payout_status' => 'Unpaid',
        ]);

        Sanctum::actingAs($driver);
        $rekap = $this->getJson('/api/driver/deposits/outstanding')->assertOk()->json();

        // Flat 10.000, BUKAN 20% x 500.000 = 100.000.
        $this->assertSame(10000.0, (float) $rekap['grand_total']);
    }

    /** Nominal dihitung server dari transaksi terkunci, tidak diterima klien. */
    public function test_setoran_mengunci_utang_dan_menghitung_nominal_sendiri(): void
    {
        $this->setRate();
        $driver = $this->driver();
        $t1 = $this->utangOrder($driver, 100000, '2026-08-20');
        $t2 = $this->utangOrder($driver, 100000, '2026-08-21');

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/deposits', [
            'dates'  => ['2026-08-20'],
            // Klien mencoba menyuntikkan nominal — harus diabaikan total.
            'amount' => 1,
        ])->assertStatus(201);

        $deposit = DriverDeposit::first();
        $this->assertSame(20000.0, (float) $deposit->amount);
        $this->assertSame(1, $deposit->transactions_count);

        // Hanya transaksi tanggal itu yang terkunci.
        $this->assertSame('Processing', $t1->fresh()->payout_status);
        $this->assertSame($deposit->id, $t1->fresh()->driver_deposit_id);
        $this->assertSame('Unpaid', $t2->fresh()->payout_status);
    }

    public function test_utang_yang_sedang_disetor_hilang_dari_rekap(): void
    {
        $this->setRate();
        $driver = $this->driver();
        $this->utangOrder($driver, 100000, '2026-08-20');

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/deposits', ['dates' => ['2026-08-20']])->assertStatus(201);

        // Setor kedua kali untuk tanggal yang sama harus ditolak — uangnya
        // sudah terkunci, tidak ada lagi yang perlu disetor.
        $this->postJson('/api/driver/deposits', ['dates' => ['2026-08-20']])
            ->assertStatus(422);

        $this->assertSame(0.0, (float) $this->getJson('/api/driver/deposits/outstanding')->json('grand_total'));
    }

    /**
     * Satu transaksi tidak boleh dilunasi lewat setoran DAN dipotong lewat
     * pencairan. Keduanya berbagi `payout_status`, jadi ini terjaga secara
     * struktural — test ini memastikan struktur itu tidak diam-diam berubah.
     */
    public function test_utang_dalam_setoran_tidak_ikut_terbawa_pencairan(): void
    {
        $this->setRate();
        $driver = $this->driver();
        $utang = $this->utangOrder($driver, 100000, '2026-08-20');

        // Pemasukan besar supaya pencairan lolos ambang minimum.
        $b = Booking::create([
            'cso_id' => $driver->id, 'driver_id' => $driver->id,
            'zone_id' => $this->zone()->id, 'price' => 500000, 'status' => 'Completed',
        ]);
        Transaction::create([
            'booking_id' => $b->id, 'method' => 'QRIS',
            'amount' => 500000, 'payout_status' => 'Unpaid',
        ]);

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/deposits', ['dates' => ['2026-08-20']])->assertStatus(201);
        $this->postJson('/api/driver/withdrawals')->assertStatus(201);

        // Transaksi utang tetap terikat ke SETORAN, bukan tertarik pencairan.
        $utang = $utang->fresh();
        $this->assertNotNull($utang->driver_deposit_id);
        $this->assertNull($utang->withdrawal_id);
    }

    public function test_supir_tidak_bisa_membaca_setoran_supir_lain(): void
    {
        $this->setRate();
        $a = $this->driver();
        $this->utangOrder($a, 100000, '2026-08-20');
        Sanctum::actingAs($a);
        $this->postJson('/api/driver/deposits', ['dates' => ['2026-08-20']])->assertStatus(201);
        $deposit = DriverDeposit::first();

        $b = User::create([
            'name' => 'Supir B', 'username' => 'supirb', 'email' => 'b@x.test',
            'password' => bcrypt('x'), 'role' => 'driver', 'active' => true,
        ]);
        DriverProfile::create([
            'user_id' => $b->id, 'car_model' => 'Xenia', 'plate_number' => 'KT 9 ZZ',
        ]);

        Sanctum::actingAs($b);
        $this->getJson('/api/driver/deposits/' . $deposit->id)->assertStatus(403);
    }

    // === Verifikasi admin =================================================

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'active' => true,
        ]);
    }

    public function test_admin_menyetujui_setoran_melunasi_utang(): void
    {
        $this->setRate();
        $this->setBatas(10000);
        $driver = $this->driver();
        $this->utangOrder($driver, 100000, '2026-08-20');

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/deposits', ['dates' => ['2026-08-20']])->assertStatus(201);
        $deposit = DriverDeposit::first();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/driver-deposits/{$deposit->id}/approve")->assertOk();

        $this->assertSame('Approved', $deposit->fresh()->status);
        $this->assertSame('Paid', Transaction::where('driver_deposit_id', $deposit->id)->first()->payout_status);

        // Utang lunas -> supir boleh masuk antrian lagi.
        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/status', [
            'action' => 'join', 'latitude' => self::APT_LAT, 'longitude' => self::APT_LNG,
        ])->assertOk();
    }

    public function test_admin_menolak_setoran_mengembalikan_utang(): void
    {
        $this->setRate();
        $driver = $this->driver();
        $this->utangOrder($driver, 100000, '2026-08-20');

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/deposits', ['dates' => ['2026-08-20']])->assertStatus(201);
        $deposit = DriverDeposit::first();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/driver-deposits/{$deposit->id}/reject", [
            'admin_note' => 'Uang kurang Rp 5.000.',
        ])->assertOk();

        $t = Transaction::where('booking_id', Booking::first()->id)->first();
        $this->assertSame('Unpaid', $t->payout_status);
        $this->assertNull($t->driver_deposit_id);

        // Tagihannya muncul lagi & bisa diajukan ulang.
        Sanctum::actingAs($driver);
        $this->assertSame(20000.0, (float) $this->getJson('/api/driver/deposits/outstanding')->json('grand_total'));
    }

    public function test_alasan_wajib_saat_menolak(): void
    {
        $this->setRate();
        $driver = $this->driver();
        $this->utangOrder($driver, 100000, '2026-08-20');
        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/deposits', ['dates' => ['2026-08-20']])->assertStatus(201);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/admin/driver-deposits/' . DriverDeposit::first()->id . '/reject', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_note');
    }

    /**
     * KRITIS — pelajaran yang sama dengan adminRejectWithdrawal: menolak
     * setoran yang sudah disetujui akan menghidupkan kembali utang yang sudah
     * dibayar.
     */
    public function test_setoran_yang_sudah_diproses_tidak_bisa_diproses_lagi(): void
    {
        $this->setRate();
        $driver = $this->driver();
        $this->utangOrder($driver, 100000, '2026-08-20');
        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/deposits', ['dates' => ['2026-08-20']])->assertStatus(201);
        $deposit = DriverDeposit::first();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/driver-deposits/{$deposit->id}/approve")->assertOk();

        $this->postJson("/api/admin/driver-deposits/{$deposit->id}/reject", ['admin_note' => 'ganti pikiran'])
            ->assertStatus(422);
        $this->postJson("/api/admin/driver-deposits/{$deposit->id}/approve")
            ->assertStatus(422);

        // Utangnya tetap lunas — tidak hidup lagi.
        $this->assertSame('Paid', Transaction::where('driver_deposit_id', $deposit->id)->first()->payout_status);
    }
}
