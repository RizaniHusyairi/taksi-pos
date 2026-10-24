<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\DriverQueue;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tombol "Rolling Antrian" di panel admin menjalankan rotasi harian
 * (queue:rotate) di luar jadwal 00:00.
 */
class AdminRotateQueueTest extends TestCase
{
    use RefreshDatabase;

    private function driver(int $line, string $status): User
    {
        $user = User::create([
            'name' => "Supir $line", 'username' => "supir$line", 'email' => "s$line@x.test",
            'phone_number' => '0812000000' . str_pad($line, 2, '0', STR_PAD_LEFT),
            'password' => bcrypt('x'), 'role' => 'driver', 'active' => true,
        ]);
        DriverProfile::create([
            'user_id' => $user->id, 'plate_number' => "KT $line AB",
            'line_number' => $line, 'status' => $status,
        ]);

        return $user;
    }

    public function test_admin_menjalankan_rotasi_manual(): void
    {
        Setting::updateOrCreate(['key' => 'daily_start_line'], ['value' => 1]);
        $standby = $this->driver(1, 'standby');
        $ontrip = $this->driver(2, 'ontrip');
        $this->driver(3, 'offline');

        Sanctum::actingAs(User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'a@x.test',
            'phone_number' => '081299999999',
            'password' => bcrypt('x'), 'role' => 'admin', 'active' => true,
        ]));

        $this->postJson('/api/admin/queue/rotate')
            ->assertOk()
            ->assertJson(['previous_start_line' => 1, 'start_line' => 2]);

        // Antrian diisi ulang semua supir; giliran mulai dari L2.
        $this->assertSame(3, DriverQueue::count());
        $this->assertSame(0, DriverQueue::where('user_id', $ontrip->id)->value('sort_order'));

        // Supir standby jadi offline, yang sedang mengantar tidak diganggu.
        $this->assertSame('offline', $standby->driverProfile->fresh()->status);
        $this->assertSame('ontrip', $ontrip->driverProfile->fresh()->status);
    }

    public function test_selain_admin_ditolak(): void
    {
        Sanctum::actingAs($this->driver(1, 'standby'));
        $this->postJson('/api/admin/queue/rotate')->assertForbidden();
    }
}
