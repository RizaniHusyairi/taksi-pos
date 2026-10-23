<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DriverProfile;
use App\Models\DriverQueue;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Home CSO: `cash` hanya tunai di kasir, order batal tidak dijumlahkan,
 * antrian teratas & pengingat setoran ikut dikirim.
 */
class CsoDashboardStatsTest extends TestCase
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

    private function tx(User $cso, User $driver, string $method, float $amount, string $status = 'Completed'): void
    {
        $zone = Zone::firstOrCreate(['name' => 'Kota'], ['price' => $amount]);
        $booking = Booking::create([
            'cso_id' => $cso->id, 'driver_id' => $driver->id, 'zone_id' => $zone->id,
            'price' => $amount, 'status' => $status,
        ]);
        Transaction::create([
            'booking_id' => $booking->id, 'method' => $method,
            'amount' => $amount, 'payout_status' => 'Unpaid',
        ]);
    }

    private function antre(User $driver, int $urut, int $lambung): void
    {
        DriverProfile::create([
            'user_id' => $driver->id, 'car_model' => 'x', 'plate_number' => "KT $lambung",
            'line_number' => $lambung, 'status' => 'standby', 'location_updated_at' => now(),
        ]);
        DriverQueue::create(['user_id' => $driver->id, 'sort_order' => $urut, 'latitude' => 0, 'longitude' => 0]);
    }

    public function test_angka_hari_ini_dipisah_per_metode_tanpa_order_batal(): void
    {
        $cso = $this->user('cso');
        $driver = $this->user('driver');
        $this->tx($cso, $driver, 'CashCSO', 100000);
        $this->tx($cso, $driver, 'CashDriver', 50000);
        $this->tx($cso, $driver, 'QRIS', 70000);
        $this->tx($cso, $driver, 'CashCSO', 999000, 'Cancelled');

        Sanctum::actingAs($cso);
        $this->getJson('/api/cso/dashboard-stats')->assertOk()
            ->assertJsonPath('today.count', 3)
            ->assertJsonPath('today.revenue', 220000)
            ->assertJsonPath('today.cash', 100000)
            ->assertJsonPath('today.cash_driver', 50000)
            ->assertJsonPath('today.qris', 70000)
            ->assertJsonPath('today.cancelled', 1);
    }

    public function test_antrian_teratas_dan_pengingat_setoran(): void
    {
        $cso = $this->user('cso');
        $kedua = $this->user('driver', 'B');
        $pertama = $this->user('driver', 'A');
        $this->antre($kedua, 2, 7);
        $this->antre($pertama, 1, 3);
        $this->tx($cso, $pertama, 'CashCSO', 60000);

        Sanctum::actingAs($cso);
        $res = $this->getJson('/api/cso/dashboard-stats')->assertOk();

        $this->assertSame($pertama->id, $res->json('queue.next.0.id'));
        $this->assertTrue($res->json('queue.next.0.is_next'));
        $this->assertSame(3, $res->json('queue.next.0.line_number'));
        $this->assertFalse($res->json('queue.next.1.is_next'));
        $this->assertEquals(60000, $res->json('deposit.outstanding_total'));
        $this->assertSame(now()->toDateString(), $res->json('deposit.oldest_date'));
    }
}
