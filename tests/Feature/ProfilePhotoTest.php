<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Foto profil supir & CSO: unggah, ganti (file lama terhapus), hapus. */
class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'username' => $role, 'email' => "$role@x.test",
            'password' => bcrypt('x'), 'role' => $role, 'active' => true,
        ]);
    }

    public function test_supir_dan_cso_bisa_mengunggah_foto(): void
    {
        foreach (['driver', 'cso'] as $role) {
            $user = $this->user($role);
            Sanctum::actingAs($user);

            $path = $this->postJson("/api/$role/profile/photo", [
                'photo' => UploadedFile::fake()->image('avatar.jpg', 600, 600),
            ])->assertOk()->json('photo_path');

            $this->assertSame($path, $user->fresh()->photo_path);
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_mengganti_foto_menghapus_file_lama(): void
    {
        $user = $this->user('driver');
        Sanctum::actingAs($user);

        $lama = $this->postJson('/api/driver/profile/photo', [
            'photo' => UploadedFile::fake()->image('a.jpg'),
        ])->json('photo_path');
        $baru = $this->postJson('/api/driver/profile/photo', [
            'photo' => UploadedFile::fake()->image('b.png'),
        ])->json('photo_path');

        $this->assertNotSame($lama, $baru);
        Storage::disk('public')->assertMissing($lama);
        Storage::disk('public')->assertExists($baru);
    }

    public function test_hapus_foto(): void
    {
        $user = $this->user('cso');
        Sanctum::actingAs($user);
        $path = $this->postJson('/api/cso/profile/photo', [
            'photo' => UploadedFile::fake()->image('a.jpg'),
        ])->json('photo_path');

        $this->deleteJson('/api/cso/profile/photo')->assertOk();

        $this->assertNull($user->fresh()->photo_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_bukan_gambar_atau_terlalu_besar_ditolak(): void
    {
        $user = $this->user('driver');
        Sanctum::actingAs($user);

        $this->postJson('/api/driver/profile/photo', [
            'photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
        $this->postJson('/api/driver/profile/photo', [
            'photo' => UploadedFile::fake()->image('besar.jpg')->size(6000),
        ])->assertStatus(422);

        $this->assertNull($user->fresh()->photo_path);
    }
}
