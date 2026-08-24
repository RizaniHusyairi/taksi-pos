<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DriverProfile;
use App\Models\DriverQueue;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Supir tidak boleh berada di antrian selama ordernya belum selesai.
 *
 * Ini BUKAN sekadar pengetatan. Tanpa penjaga ini supir masuk antrian lagi
 * sendirinya: processOrder() mengeluarkannya dari antrian tanpa mengubah status
 * profil (tetap 'standby'), attachVirtualStatus() mengoreksinya jadi 'offline'
 * karena tidak ada di antrian, lalu auto-join menarik siapa pun yang 'offline'
 * dan berada di area. Test pertama di bawah memotret rantai itu apa adanya.
 */
class ActiveOrderQueueGateTest extends TestCase
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
            'latitude'            => self::APT_LAT,
            'longitude'           => self::APT_LNG,
            'location_updated_at' => now()->subSeconds(30),
            'last_in_area'        => true,
        ]);

        Sanctum::actingAs($user);
        return $user;
    }

    private function order(User $driver, string $status): Booking
    {
        return Booking::create([
            'cso_id'    => $driver->id,
            'driver_id' => $driver->id,
            'zone_id'   => Zone::firstOrCreate(['name' => 'Kota'], ['price' => 100000])->id,
            'price'     => 100000,
            'status'    => $status,
        ]);
    }

    private function join()
    {
        return $this->postJson('/api/driver/status', [
            'action' => 'join', 'latitude' => self::APT_LAT, 'longitude' => self::APT_LNG,
        ]);
    }

    private function ping()
    {
        return $this->postJson('/api/driver/location', [
            'latitude' => self::APT_LAT, 'longitude' => self::APT_LNG,
        ]);
    }

    // === Pintu manual ======================================================

    public function test_order_assigned_memblokir_join_manual(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, 'Assigned');

        $this->join()
            ->assertStatus(422)
            ->assertJson(['has_active_order' => true])
            ->assertJsonFragment(['message' => 'Anda masih punya order yang belum dijalankan (order #'
                . $order->id . '). Selesaikan dulu, baru bisa masuk antrian lagi.']);

        $this->assertSame(0, DriverQueue::where('user_id', $driver->id)->count());
    }

    public function test_order_ontrip_memblokir_join_manual(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, 'OnTrip');

        $this->join()->assertStatus(422)->assertJson(['has_active_order' => true]);

        // Pesannya berbeda: supir sedang mengantar, bukan menunda.
        $this->assertStringContainsString(
            'sedang mengantar penumpang (order #' . $order->id . ')',
            $this->join()->json('message')
        );
    }

    // === Pintu otomatis (di sinilah bug-nya paling terasa) =================

    /**
     * Potret rantai bug aslinya: supir yang baru dapat order berstatus
     * 'offline' menurut profil, berada di area, dan sebelumnya ditarik masuk
     * antrian oleh auto-join tanpa melakukan apa pun.
     */
    public function test_auto_join_tidak_menarik_supir_yang_punya_order_aktif(): void
    {
        $driver = $this->driver();
        $this->order($driver, 'Assigned');

        $this->ping()
            ->assertOk()
            ->assertJson(['has_active_order' => true, 'status' => 'offline']);

        $this->assertSame(0, DriverQueue::where('user_id', $driver->id)->count());
        $this->assertSame('offline', $driver->driverProfile->fresh()->status);
    }

    public function test_auto_join_tetap_bekerja_untuk_supir_tanpa_order(): void
    {
        $driver = $this->driver();

        $this->ping()->assertOk()->assertJson(['status' => 'standby']);

        $this->assertSame(1, DriverQueue::where('user_id', $driver->id)->count());
    }

    // === Order yang sudah tidak aktif tidak boleh ikut memblokir ===========

    public function test_order_completed_tidak_memblokir(): void
    {
        $driver = $this->driver();
        $this->order($driver, 'Completed');

        $this->join()->assertOk();
        $this->assertSame(1, DriverQueue::where('user_id', $driver->id)->count());
    }

    public function test_order_cancelled_tidak_memblokir(): void
    {
        $driver = $this->driver();
        $this->order($driver, 'Cancelled');

        $this->join()->assertOk();
    }

    /** Order milik supir LAIN tentu tidak boleh mengunci siapa pun. */
    public function test_order_supir_lain_tidak_memblokir(): void
    {
        $lain = User::create([
            'name' => 'Supir Lain', 'username' => 'lain', 'email' => 'l@x.test',
            'password' => bcrypt('x'), 'role' => 'driver', 'active' => true,
        ]);
        DriverProfile::create([
            'user_id' => $lain->id, 'car_model' => 'Xenia', 'plate_number' => 'KT 9 ZZ',
        ]);
        Booking::create([
            'cso_id' => $lain->id, 'driver_id' => $lain->id,
            'zone_id' => Zone::firstOrCreate(['name' => 'Kota'], ['price' => 100000])->id,
            'price' => 100000, 'status' => 'OnTrip',
        ]);

        $this->driver();
        $this->join()->assertOk();
    }

    /**
     * Setelah supir menekan "Selesai", antriannya harus terbuka lagi — kalau
     * tidak, penjaga ini berubah dari perbaikan jadi jebakan.
     */
    public function test_setelah_order_selesai_bisa_masuk_antrian_lagi(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, 'OnTrip');

        $this->join()->assertStatus(422);

        $this->postJson("/api/driver/bookings/{$order->id}/complete")->assertOk();

        $this->join()->assertOk();
        $this->assertSame(1, DriverQueue::where('user_id', $driver->id)->count());
    }
}
