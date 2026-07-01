<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DriverProfile;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Guard status booking + kebenaran perhitungan saldo driver.
 */
class DriverMoneyTest extends TestCase
{
    use RefreshDatabase;

    private function driver(): User
    {
        $u = User::create([
            'name' => 'Driver', 'username' => 'driver', 'email' => 'd@x.test',
            'password' => bcrypt('x'), 'role' => 'driver', 'active' => true,
        ]);
        DriverProfile::create([
            'user_id' => $u->id, 'status' => 'ontrip',
            'car_model' => '', 'plate_number' => '', // kolom NOT NULL
        ]);

        return $u;
    }

    private function booking(User $driver, string $status): Booking
    {
        return Booking::create([
            'cso_id' => $driver->id, 'driver_id' => $driver->id,
            'zone_id' => null, 'price' => 50000, 'status' => $status,
        ]);
    }

    public function test_cannot_complete_booking_that_is_not_ontrip(): void
    {
        $driver = $this->driver();
        $booking = $this->booking($driver, 'Assigned'); // belum di-Start
        Sanctum::actingAs($driver);
        $this->post("/api/driver/bookings/{$booking->id}/complete")->assertStatus(422);
    }

    public function test_cannot_start_cancelled_booking(): void
    {
        $driver = $this->driver();
        $booking = $this->booking($driver, 'Cancelled');
        Sanctum::actingAs($driver);
        $this->post("/api/driver/bookings/{$booking->id}/start")->assertStatus(422);
    }

    public function test_cannot_start_completed_booking(): void
    {
        $driver = $this->driver();
        $booking = $this->booking($driver, 'Completed');
        Sanctum::actingAs($driver);
        $this->post("/api/driver/bookings/{$booking->id}/start")->assertStatus(422);
    }

    public function test_completing_self_order_twice_does_not_duplicate_debt(): void
    {
        $driver = $this->driver();
        $booking = $this->booking($driver, 'OnTrip'); // self-order (cso=driver, zone null)
        Sanctum::actingAs($driver);

        $this->post("/api/driver/bookings/{$booking->id}/complete")->assertOk();   // -> Completed + 1 hutang
        $this->post("/api/driver/bookings/{$booking->id}/complete")->assertStatus(422); // sudah selesai

        $this->assertSame(1, Transaction::where('booking_id', $booking->id)->count());
    }

    public function test_balance_is_income_share_minus_debt(): void
    {
        // rate default 0.2 (tak ada setting commission_rate).
        $driver = $this->driver();

        // Pemasukan: QRIS 100.000 Unpaid -> hak driver = 100.000 * (1-0.2) = 80.000
        $b1 = $this->booking($driver, 'Completed');
        $b1->update(['price' => 100000]);
        Transaction::create([
            'booking_id' => $b1->id, 'method' => 'QRIS', 'amount' => 100000, 'payout_status' => 'Unpaid',
        ]);

        // Hutang: 1 order manual (zone null) CashDriver -> flat 10.000
        $b2 = $this->booking($driver, 'Completed');
        Transaction::create([
            'booking_id' => $b2->id, 'method' => 'CashDriver', 'amount' => 50000, 'payout_status' => 'Unpaid',
        ]);

        Sanctum::actingAs($driver);
        // 80.000 - 10.000 = 70.000
        $this->getJson('/api/driver/balance')->assertOk()->assertJson(['balance' => 70000]);
    }
}
