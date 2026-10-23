<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Seluruh armada Angkasa Jaya adalah Honda Mobilio (DriverProfile::CAR_MODEL):
 * apa pun yang dikirim form admin atau APK lama, yang tersimpan tetap itu.
 */
class DriverCarModelTest extends TestCase
{
    use RefreshDatabase;

    private function driver(): User
    {
        $user = User::create([
            'name' => 'Supir', 'username' => 'supir', 'email' => 's@x.test',
            'phone_number' => '081200000001',
            'password' => bcrypt('x'), 'role' => 'driver', 'active' => true,
        ]);
        DriverProfile::create([
            'user_id' => $user->id, 'car_model' => 'Toyota Avanza',
            'plate_number' => 'KT 1234 AB', 'line_number' => 1, 'status' => 'offline',
        ]);

        return $user;
    }

    public function test_model_mobil_selalu_honda_mobilio(): void
    {
        $driver = $this->driver();
        $this->assertSame('Honda Mobilio', $driver->driverProfile->fresh()->car_model);

        $driver->driverProfile->update(['car_model' => 'Innova']);
        $this->assertSame('Honda Mobilio', $driver->driverProfile->fresh()->car_model);
    }

    public function test_edit_profil_supir_tidak_butuh_model_mobil(): void
    {
        $driver = $this->driver();
        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/profile/update', [
            'name' => 'Supir Baru', 'email' => 's@x.test', 'phone_number' => '081200000001',
            'username' => 'supir', 'plate_number' => 'KT 9999 ZZ',
        ])->assertOk();

        $profil = $driver->driverProfile->fresh();
        $this->assertSame('KT 9999 ZZ', $profil->plate_number);
        $this->assertSame('Honda Mobilio', $profil->car_model);
    }

    public function test_apk_lama_yang_mengirim_model_mobil_tetap_diseragamkan(): void
    {
        $driver = $this->driver();
        Sanctum::actingAs($driver);

        $this->postJson('/api/driver/profile/update', [
            'name' => 'Supir', 'email' => 's@x.test', 'phone_number' => '081200000001',
            'username' => 'supir', 'plate_number' => 'KT 1234 AB', 'car_model' => 'Avanza Hitam',
        ])->assertOk();

        $this->assertSame('Honda Mobilio', $driver->driverProfile->fresh()->car_model);
    }
}
