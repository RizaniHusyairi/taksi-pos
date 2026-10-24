<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nomor lambung (line_number) dipakai rotasi antrean harian, jadi harus angka
 * saja (tanpa "L") dan unik antar supir — baik diubah dari form Pengguna maupun
 * dari tabel Manajemen Antrian.
 */
class LineNumberTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'active' => true,
        ]);
    }

    private function driver(string $username, int $line): User
    {
        $user = User::create([
            'name' => 'Supir ' . $username, 'username' => $username, 'email' => "{$username}@x.test",
            'password' => bcrypt('x'), 'role' => 'driver', 'active' => true,
        ]);
        DriverProfile::create([
            'user_id' => $user->id, 'car_model' => 'Honda Mobilio',
            'plate_number' => 'KT 1 AB', 'line_number' => $line, 'status' => 'offline',
        ]);

        return $user;
    }

    private function editDriver(User $driver, array $extra = [])
    {
        return $this->putJson("/api/admin/users/{$driver->id}", array_merge([
            'name' => $driver->name, 'role' => 'driver', 'plate_number' => 'KT 1 AB',
        ], $extra));
    }

    public function test_form_pengguna_bisa_mengubah_nomor_lambung(): void
    {
        Sanctum::actingAs($this->admin());
        $driver = $this->driver('supir1', 1);

        $this->editDriver($driver, ['line_number' => '7'])->assertOk();

        $this->assertSame('7', (string) $driver->driverProfile->fresh()->line_number);
    }

    public function test_form_pengguna_tanpa_nomor_lambung_mempertahankan_yang_lama(): void
    {
        Sanctum::actingAs($this->admin());
        $driver = $this->driver('supir1', 4);

        $this->editDriver($driver)->assertOk();

        $this->assertSame('4', (string) $driver->driverProfile->fresh()->line_number);
    }

    public function test_supir_baru_memakai_nomor_lambung_isian_admin(): void
    {
        Sanctum::actingAs($this->admin());
        $this->driver('supir1', 1);

        $this->postJson('/api/admin/users', [
            'name' => 'Supir Baru', 'username' => 'baru', 'password' => 'rahasia1',
            'role' => 'driver', 'plate_number' => 'KT 2 CD', 'line_number' => '12',
        ])->assertCreated();

        $this->assertSame('12', (string) User::where('username', 'baru')->first()->driverProfile->line_number);
    }

    public function test_nomor_lambung_berawalan_l_ditolak_dengan_pesan_jelas(): void
    {
        Sanctum::actingAs($this->admin());
        $driver = $this->driver('supir1', 1);

        $this->editDriver($driver, ['line_number' => 'L5'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nomor lambung diisi angka saja tanpa huruf L, misalnya 5.');

        $this->postJson('/api/admin/queue/line-number', ['user_id' => $driver->id, 'line_number' => 'L5'])
            ->assertStatus(422);
        $this->postJson('/api/admin/queue/line-number', ['user_id' => $driver->id, 'line_number' => '0'])
            ->assertStatus(422);

        $this->assertSame('1', (string) $driver->driverProfile->fresh()->line_number);
    }

    public function test_nomor_lambung_yang_dipakai_supir_lain_ditolak(): void
    {
        Sanctum::actingAs($this->admin());
        $this->driver('supir1', 5);
        $driver = $this->driver('supir2', 6);

        $this->editDriver($driver, ['line_number' => '5'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nomor lambung 5 sudah dipakai driver lain.');

        $this->postJson('/api/admin/queue/line-number', ['user_id' => $driver->id, 'line_number' => '5'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nomor lambung 5 sudah dipakai driver lain.');

        $this->assertSame('6', (string) $driver->driverProfile->fresh()->line_number);
    }

    public function test_endpoint_antrean_mengubah_nomor_lambung(): void
    {
        Sanctum::actingAs($this->admin());
        $driver = $this->driver('supir1', 3);

        $this->postJson('/api/admin/queue/line-number', ['user_id' => $driver->id, 'line_number' => '9'])
            ->assertOk()
            ->assertJsonPath('message', 'Nomor lambung diubah menjadi L9.');

        $this->assertSame('9', (string) $driver->driverProfile->fresh()->line_number);
    }
}
