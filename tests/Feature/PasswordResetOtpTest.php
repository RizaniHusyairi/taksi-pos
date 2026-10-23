<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PasswordResetController;
use App\Jobs\SendWhatsAppMessage;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PasswordResetOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function user(string $role = 'driver', ?string $phone = '081234567890'): User
    {
        return User::create([
            'name'         => 'Uji ' . $role,
            'username'     => $role . '01',
            'email'        => $role . '01@x.test',
            'phone_number' => $phone,
            'password'     => bcrypt('lama123'),
            'role'         => $role,
            'active'       => true,
        ]);
    }

    /** Minta kode lalu ambil 6 digitnya dari pesan WA yang diantrekan. */
    private function mintaKode(string $login): string
    {
        $this->postJson('/api/password/forgot', ['login' => $login])->assertOk();

        $kode = null;
        Queue::assertPushed(SendWhatsAppMessage::class, function ($job) use (&$kode) {
            if (preg_match('/\*(\d{6})\*/', $job->message, $m)) {
                $kode = $m[1];
            }
            return true;
        });

        $this->assertNotNull($kode);
        return $kode;
    }

    public function test_kode_dikirim_ke_nomor_terdaftar_walau_diketik_format_lain(): void
    {
        $user = $this->user();

        $kode = $this->mintaKode('+62 812-3456-7890');

        Queue::assertPushed(SendWhatsAppMessage::class, fn ($job) => $job->target === '081234567890');
        $otp = PasswordResetOtp::firstOrFail();
        $this->assertSame($user->id, $otp->user_id);
        $this->assertNotSame($kode, $otp->code_hash, 'kode wajib disimpan sebagai hash');
        $this->assertTrue(Hash::check($kode, $otp->code_hash));
    }

    public function test_jawaban_sama_untuk_akun_tak_dikenal_admin_dan_tanpa_nomor(): void
    {
        $this->user('admin');
        $tanpaNomor = $this->user('cso', null);

        $pesan = $this->postJson('/api/password/forgot', ['login' => 'tidakada'])->assertOk()->json('message');
        $this->postJson('/api/password/forgot', ['login' => 'admin01'])->assertOk()->assertJson(['message' => $pesan]);
        $this->postJson('/api/password/forgot', ['login' => $tanpaNomor->username])->assertOk()->assertJson(['message' => $pesan]);

        Queue::assertNothingPushed();
        $this->assertSame(0, PasswordResetOtp::count());
    }

    public function test_reset_mengganti_password_dan_mengeluarkan_semua_perangkat(): void
    {
        $user = $this->user();
        $user->createToken('hp_lama');
        $kode = $this->mintaKode('driver01');

        $this->postJson('/api/password/reset', [
            'login' => 'driver01', 'code' => $kode,
            'password' => 'baru1234', 'password_confirmation' => 'baru1234',
        ])->assertOk();

        $this->assertTrue(Hash::check('baru1234', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());

        // Kode sekali pakai.
        $this->postJson('/api/password/reset', [
            'login' => 'driver01', 'code' => $kode,
            'password' => 'lagi1234', 'password_confirmation' => 'lagi1234',
        ])->assertStatus(422);
        $this->assertTrue(Hash::check('baru1234', $user->fresh()->password));
    }

    public function test_kode_hangus_setelah_lima_kali_salah(): void
    {
        $user = $this->user();
        $kode = $this->mintaKode('driver01');
        $salah = $kode === '000000' ? '111111' : '000000';

        for ($i = 0; $i < PasswordResetController::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/password/reset', [
                'login' => 'driver01', 'code' => $salah,
                'password' => 'baru1234', 'password_confirmation' => 'baru1234',
            ])->assertStatus(422);
        }

        // Kode yang benar pun sudah tidak diterima.
        $this->postJson('/api/password/reset', [
            'login' => 'driver01', 'code' => $kode,
            'password' => 'baru1234', 'password_confirmation' => 'baru1234',
        ])->assertStatus(422);
        $this->assertTrue(Hash::check('lama123', $user->fresh()->password));
    }

    public function test_kode_kedaluwarsa_ditolak(): void
    {
        $this->user();
        $kode = $this->mintaKode('driver01');

        $this->travel(6)->minutes();

        $this->postJson('/api/password/reset', [
            'login' => 'driver01', 'code' => $kode,
            'password' => 'baru1234', 'password_confirmation' => 'baru1234',
        ])->assertStatus(422);
    }

    public function test_jeda_dan_batas_per_jam_membatasi_pengiriman(): void
    {
        $this->user();

        $this->postJson('/api/password/forgot', ['login' => 'driver01'])->assertOk();
        $this->postJson('/api/password/forgot', ['login' => 'driver01'])->assertOk(); // masih jeda 60 dtk
        Queue::assertPushed(SendWhatsAppMessage::class, 1);

        $this->travel(2)->minutes();
        $this->postJson('/api/password/forgot', ['login' => 'driver01'])->assertOk();
        $this->travel(2)->minutes();
        $this->postJson('/api/password/forgot', ['login' => 'driver01'])->assertOk();
        $this->travel(2)->minutes();
        $this->postJson('/api/password/forgot', ['login' => 'driver01'])->assertOk(); // ke-4 dalam 1 jam

        Queue::assertPushed(SendWhatsAppMessage::class, 3);
    }
}

