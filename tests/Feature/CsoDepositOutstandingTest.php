<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Menu Setoran Tunai CSO: rincian transaksi per tanggal (untuk mencocokkan
 * uang fisik) dan total yang sudah diserahkan tapi menunggu verifikasi.
 */
class CsoDepositOutstandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function user(string $role, string $s = ''): User
    {
        return User::create([
            'name' => ucfirst($role) . $s, 'username' => $role . $s, 'email' => "$role$s@x.test",
            'password' => bcrypt('x'), 'role' => $role, 'active' => true,
        ]);
    }

    private function tunai(User $cso, User $driver, float $amount, string $waktu): Transaction
    {
        $zone = Zone::firstOrCreate(['name' => 'Samarinda'], ['price' => $amount]);
        $booking = Booking::create([
            'cso_id' => $cso->id, 'driver_id' => $driver->id, 'zone_id' => $zone->id,
            'price' => $amount, 'status' => 'Completed',
        ]);
        $t = Transaction::create([
            'booking_id' => $booking->id, 'method' => 'CashCSO',
            'amount' => $amount, 'payout_status' => 'Unpaid',
        ]);
        $t->forceFill(['created_at' => $waktu])->save();

        return $t;
    }

    public function test_rincian_per_tanggal_hanya_milik_cso_dan_tanggal_itu(): void
    {
        $cso = $this->user('cso');
        $lain = $this->user('cso', '2');
        $driver = $this->user('driver');
        $this->tunai($cso, $driver, 50000, '2026-10-20 08:00:00');
        $this->tunai($cso, $driver, 70000, '2026-10-20 15:30:00');
        $this->tunai($cso, $driver, 90000, '2026-10-21 09:00:00');
        $this->tunai($lain, $driver, 99000, '2026-10-20 10:00:00');

        Sanctum::actingAs($cso);
        $res = $this->getJson('/api/cso/deposits/outstanding/2026-10-20')->assertOk();

        $this->assertCount(2, $res->json('transactions'));
        $this->assertSame('Samarinda', $res->json('transactions.0.destination'));
        $this->assertSame('Driver', $res->json('transactions.0.driver'));
        $this->assertEquals(50000, $res->json('transactions.0.amount'));
    }

    public function test_format_tanggal_salah_ditolak(): void
    {
        Sanctum::actingAs($this->user('cso'));
        $this->getJson('/api/cso/deposits/outstanding/kemarin')->assertStatus(422);
    }

    public function test_total_menunggu_verifikasi_ikut_dikirim(): void
    {
        $cso = $this->user('cso');
        $driver = $this->user('driver');
        $this->tunai($cso, $driver, 50000, '2026-10-20 08:00:00');
        $this->tunai($cso, $driver, 80000, '2026-10-21 08:00:00');

        Sanctum::actingAs($cso);
        $this->postJson('/api/cso/deposits', ['dates' => ['2026-10-20']])->assertStatus(201);

        $this->getJson('/api/cso/deposits/outstanding')->assertOk()
            ->assertJsonPath('grand_total', 80000)
            ->assertJsonPath('pending_total', 50000)
            ->assertJsonPath('pending_count', 1);
    }
}
