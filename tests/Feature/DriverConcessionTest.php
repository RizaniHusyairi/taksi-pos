<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\ConcessionPayment;
use App\Models\DriverConcession;
use App\Models\DriverProfile;
use App\Models\DriverQueue;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawals;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Konsesi bulanan supir.
 *
 * Janji yang dikunci:
 *  1. Supir diblokir dari antrian HANYA bila cicilan bulan berjalan Rp 0 —
 *     di pintu manual maupun auto-join.
 *  2. Cicilan dari dompet mengurangi saldo yang SAMA yang ditampilkan dan
 *     yang dicairkan; tidak bisa melebihi saldo maupun sisa konsesi.
 *  3. Potongan ikut terkunci ke pencairan, dan kembali bila pencairan ditolak.
 */
class DriverConcessionTest extends TestCase
{
    use RefreshDatabase;

    private const APT_LAT = -0.371975;
    private const APT_LNG = 117.257919;

    private function driver(string $username = 'supir', int $line = 1): User
    {
        $user = User::create([
            'name' => 'Supir', 'username' => $username, 'email' => $username . '@x.test',
            'password' => bcrypt('x'), 'role' => 'driver', 'active' => true,
        ]);

        DriverProfile::create([
            'user_id'             => $user->id,
            'car_model'           => 'Avanza',
            'plate_number'        => 'KT 1234 AB',
            'line_number'         => $line,
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

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'active' => true,
        ]);
    }

    private function setFee(int $rupiah = 300000): void
    {
        Setting::updateOrCreate(['key' => 'concession_monthly_fee'], ['value' => $rupiah]);
        Setting::updateOrCreate(['key' => 'commission_rate'], ['value' => 0.2]);
        Setting::forgetMemo();
    }

    /** Pemasukan QRIS → saldo bersih = 80% dari $harga (komisi 20%). */
    private function pemasukan(User $driver, int $harga): void
    {
        $zone = Zone::create(['name' => 'Kota', 'price' => $harga]);
        $b = Booking::create([
            'cso_id' => $driver->id, 'driver_id' => $driver->id,
            'zone_id' => $zone->id, 'price' => $harga, 'status' => 'Completed',
        ]);
        Transaction::create([
            'booking_id' => $b->id, 'method' => 'QRIS',
            'amount' => $harga, 'payout_status' => 'Unpaid',
        ]);
    }

    private function join(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/driver/status', [
            'action' => 'join', 'latitude' => self::APT_LAT, 'longitude' => self::APT_LNG,
        ]);
    }

    // === Penerbitan tagihan ===============================================

    public function test_perintah_terbit_idempoten(): void
    {
        $this->setFee();
        $this->driver('a');
        $this->driver('b', 2);

        $this->artisan('concession:issue')->assertSuccessful();
        $this->artisan('concession:issue')->assertSuccessful();

        $this->assertSame(2, DriverConcession::count());
        $this->assertSame(300000, DriverConcession::first()->amount);
    }

    public function test_fee_nol_berarti_fitur_mati(): void
    {
        $this->setFee(0);
        $driver = $this->driver();

        Sanctum::actingAs($driver);
        $this->join()->assertOk();
        $this->assertSame(0, DriverConcession::count());
        $this->assertNull($this->getJson('/api/driver/balance')->json('concession'));
    }

    // === Gerbang antrian ==================================================

    public function test_belum_bayar_sama_sekali_memblokir_join_manual(): void
    {
        $this->setFee();
        $driver = $this->driver();

        Sanctum::actingAs($driver);
        $this->join()->assertStatus(422)->assertJson(['concession_blocked' => true]);
        $this->assertSame(0, DriverQueue::where('user_id', $driver->id)->count());
    }

    public function test_belum_bayar_juga_memblokir_auto_join(): void
    {
        $this->setFee();
        $driver = $this->driver();

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/location', [
            'latitude' => self::APT_LAT, 'longitude' => self::APT_LNG,
        ])->assertOk()->assertJson(['concession_blocked' => true, 'status' => 'offline']);

        $this->assertSame(0, DriverQueue::where('user_id', $driver->id)->count());
    }

    public function test_cicilan_sebagian_membuka_antrian(): void
    {
        $this->setFee();
        $driver = $this->driver();
        $this->pemasukan($driver, 100000); // saldo 80.000

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/concession/pay', ['amount' => 10000])->assertOk();

        $this->join()->assertOk();
        $this->assertSame(1, DriverQueue::where('user_id', $driver->id)->count());
    }

    public function test_konsesi_dibebaskan_tidak_memblokir(): void
    {
        $this->setFee();
        $driver = $this->driver();
        $admin = $this->admin();
        $this->artisan('concession:issue');
        $c = DriverConcession::first();

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/concessions/{$c->id}/waive", [])->assertStatus(422);
        $this->postJson("/api/admin/concessions/{$c->id}/waive", ['note' => 'Cuti'])->assertOk();

        Sanctum::actingAs($driver);
        $this->join()->assertOk();
    }

    // === Bayar dari dompet ================================================

    public function test_cicilan_mengurangi_saldo_dan_bertahap_sampai_lunas(): void
    {
        $this->setFee(100000);
        $driver = $this->driver();
        $this->pemasukan($driver, 200000); // saldo 160.000

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/concession/pay', ['amount' => 40000])
            ->assertOk()
            ->assertJsonPath('data.balance', 120000)
            ->assertJsonPath('data.concession.paid', 40000)
            ->assertJsonPath('data.concession.remaining', 60000);

        $this->postJson('/api/driver/concession/pay', ['amount' => 60000])
            ->assertOk()
            ->assertJsonPath('data.balance', 60000)
            ->assertJsonPath('data.concession.status', 'Paid');

        // Sudah lunas → cicilan berikutnya ditolak.
        $this->postJson('/api/driver/concession/pay', ['amount' => 1])->assertStatus(422);
    }

    public function test_tidak_boleh_melebihi_saldo_atau_sisa(): void
    {
        $this->setFee(100000);
        $driver = $this->driver();
        $this->pemasukan($driver, 50000); // saldo 40.000

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/concession/pay', ['amount' => 50000])->assertStatus(422); // > saldo
        $this->assertSame(0, ConcessionPayment::count());

        $this->pemasukan($driver, 500000);
        $this->postJson('/api/driver/concession/pay', ['amount' => 100001])->assertStatus(422); // > sisa
    }

    public function test_saldo_kosong_bisa_dibayar_tunai_lewat_admin(): void
    {
        $this->setFee();
        $driver = $this->driver();
        $admin = $this->admin();

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/concession/pay', ['amount' => 10000])->assertStatus(422);
        $c = DriverConcession::first();

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/concessions/{$c->id}/cash", ['amount' => 300000])->assertOk();
        $this->assertSame('Paid', $c->fresh()->status);

        $list = $this->getJson('/api/admin/concessions')->assertOk();
        $list->assertJsonPath('summary.collected', 300000)->assertJsonPath('summary.blocked', 0);

        // Bayar tunai TIDAK mengurangi saldo dompet.
        Sanctum::actingAs($driver);
        $this->getJson('/api/driver/balance')->assertJsonPath('balance', 0);
        $this->join()->assertOk();
    }

    // === Pencairan ========================================================

    public function test_potongan_terkunci_ke_pencairan_dan_kembali_saat_ditolak(): void
    {
        $this->setFee();
        $driver = $this->driver();
        $admin = $this->admin();
        $this->pemasukan($driver, 500000); // saldo 400.000

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/concession/pay', ['amount' => 100000])->assertOk();
        $this->postJson('/api/driver/withdrawals')->assertStatus(201);

        $w = Withdrawals::first();
        $this->assertEquals(300000, (float) $w->amount);
        $this->assertSame($w->id, ConcessionPayment::first()->withdrawal_id);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/withdrawals/{$w->id}/reject")->assertOk();

        $this->assertNull(ConcessionPayment::first()->withdrawal_id);
        Sanctum::actingAs($driver);
        $this->getJson('/api/driver/balance')->assertJsonPath('balance', 300000);
    }

    // === Fee trip mandiri → konsesi =======================================

    /** Trip "Dapat Penumpang Sendiri" yang sedang berjalan, lalu diselesaikan. */
    private function selesaikanTripMandiri(User $driver, int $harga = 100000): void
    {
        $b = Booking::create([
            'cso_id' => $driver->id, 'driver_id' => $driver->id, 'zone_id' => null,
            'manual_destination' => 'Hotel A', 'price' => $harga, 'status' => 'OnTrip',
        ]);

        Sanctum::actingAs($driver);
        $this->postJson("/api/driver/bookings/{$b->id}/complete")->assertOk();
    }

    public function test_fee_trip_mandiri_menjadi_cicilan_konsesi(): void
    {
        $this->setFee();
        $driver = $this->driver();

        $this->selesaikanTripMandiri($driver);

        $p = ConcessionPayment::first();
        $this->assertSame('self_trip', $p->source);
        $this->assertSame(10000, $p->amount);
        $this->assertNotNull($p->transaction_id);

        // Utang setoran TIDAK berubah (uangnya di tangan supir) dan tidak
        // dipotong dua kali dari saldo; konsesi bertambah & antrian terbuka.
        $this->getJson('/api/driver/balance')
            ->assertJsonPath('balance', -10000)
            ->assertJsonPath('breakdown.debt.manual_fee', 10000)
            ->assertJsonPath('breakdown.debt.manual_to_concession', 10000)
            ->assertJsonPath('concession.paid', 10000)
            ->assertJsonPath('concession.blocked', false);
    }

    public function test_fee_trip_mandiri_hanya_sebesar_sisa_lalu_kembali_jadi_biaya_admin(): void
    {
        $this->setFee(15000);
        $driver = $this->driver();

        $this->selesaikanTripMandiri($driver); // 10.000 → konsesi
        $this->selesaikanTripMandiri($driver); // 5.000 → konsesi (lunas), 5.000 biaya admin
        $this->selesaikanTripMandiri($driver); // konsesi lunas → seluruhnya biaya admin

        $this->assertSame([10000, 5000], ConcessionPayment::orderBy('id')->pluck('amount')->all());
        $this->assertSame('Paid', DriverConcession::first()->status);

        $this->getJson('/api/driver/balance')
            ->assertJsonPath('balance', -30000)
            ->assertJsonPath('breakdown.debt.manual_to_concession', 15000);
    }

    public function test_fitur_konsesi_mati_fee_trip_mandiri_tetap_biaya_admin(): void
    {
        $this->setFee(0);
        $driver = $this->driver();

        $this->selesaikanTripMandiri($driver);

        $this->assertSame(0, ConcessionPayment::count());
        $this->getJson('/api/driver/balance')->assertJsonPath('balance', -10000);
    }
}
