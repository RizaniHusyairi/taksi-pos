<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Booking;
use App\Models\CsoDeposit;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Notifikasi seputar setoran tunai.
 *
 * Setoran adalah titik paling sensitif di sistem ini: uang fisik berpindah
 * tangan. Yang dijaga test ini bukan sekadar "notifikasinya terkirim", tapi
 * dua hal yang lebih penting:
 *
 *   1. notifikasi TIDAK PERNAH menggagalkan setoran — akun tanpa token FCM,
 *      gateway WhatsApp yang belum dikonfigurasi, semuanya harus dilewati
 *      diam-diam;
 *   2. push dan WhatsApp berdiri sendiri — gateway WA yang kosong tidak boleh
 *      ikut membungkam push, kesalahan yang mudah terjadi kalau keduanya
 *      ditaruh dalam satu blok penjagaan.
 */
class DepositNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const WA_ADMIN = '628111222333';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        // Setting::getValue() menyimpan hasilnya di properti statis, dan
        // RefreshDatabase hanya membersihkan database — bukan memo di memori.
        // Tanpa baris ini, wa_token dari test sebelumnya masih terbaca di test
        // berikutnya, dan test "gateway WA kosong" jadi lulus/gagal palsu.
        Setting::forgetMemo();
    }

    private function aturWa(): void
    {
        Setting::updateOrCreate(['key' => 'wa_token'], ['value' => 'wag_uji.rahasia']);
        Setting::updateOrCreate(['key' => 'admin_wa_number'], ['value' => self::WA_ADMIN]);
        Setting::forgetMemo();
    }

    private function user(string $role, string $suffix = '', ?string $fcm = null): User
    {
        $u = User::create([
            'name'     => ucfirst($role) . ' ' . $suffix,
            'username' => $role . $suffix,
            'email'    => $role . $suffix . '@x.test',
            'password' => bcrypt('rahasia123'),
            'role'     => $role,
            'active'   => true,
        ]);

        if ($fcm) {
            $u->forceFill(['fcm_token' => $fcm])->save();
        }

        return $u;
    }

    /** Satu order tunai yang siap disetor. */
    private function tunai(User $cso, User $driver, string $method, float $amount): Transaction
    {
        $zone = Zone::firstOrCreate(['name' => 'Zona A'], ['price' => $amount]);

        $booking = Booking::create([
            'cso_id' => $cso->id, 'driver_id' => $driver->id, 'zone_id' => $zone->id,
            'price' => $amount, 'status' => 'Completed',
        ]);

        return Transaction::create([
            'booking_id' => $booking->id, 'method' => $method,
            'amount' => $amount, 'payout_status' => 'Unpaid',
        ]);
    }

    // === Setoran diajukan ==================================================

    public function test_setoran_cso_memicu_push_ke_cso_dan_wa_ke_admin(): void
    {
        $this->aturWa();
        $cso    = $this->user('cso', '1', 'token-cso-abc');
        $driver = $this->user('driver', '1');
        $this->tunai($cso, $driver, 'CashCSO', 150000);

        Sanctum::actingAs($cso);
        $this->postJson('/api/cso/deposits', ['dates' => [now()->toDateString()]])
            ->assertCreated();

        Queue::assertPushed(SendFcmNotification::class, function ($job) {
            return $this->prop($job, 'fcmToken') === 'token-cso-abc'
                && str_contains($this->prop($job, 'title'), 'Setoran terkirim');
        });

        Queue::assertPushed(SendWhatsAppMessage::class, function ($job) use ($cso) {
            return str_contains(json_encode($this->semuaProp($job)), self::WA_ADMIN)
                && str_contains(json_encode($this->semuaProp($job)), $cso->name);
        });
    }

    public function test_setoran_supir_memicu_push_ke_supir_dan_wa_ke_admin(): void
    {
        $this->aturWa();
        $cso    = $this->user('cso', '1');
        $driver = $this->user('driver', '1', 'token-supir-xyz');
        $this->tunai($cso, $driver, 'CashDriver', 90000);

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/deposits', ['dates' => [now()->toDateString()]])
            ->assertCreated();

        Queue::assertPushed(SendFcmNotification::class, fn ($job) =>
            $this->prop($job, 'fcmToken') === 'token-supir-xyz');
        Queue::assertPushed(SendWhatsAppMessage::class);
    }

    // === Yang paling mudah luput ===========================================

    public function test_penyetor_tanpa_token_fcm_tetap_bisa_menyetor(): void
    {
        $this->aturWa();
        $cso    = $this->user('cso', '1');           // sengaja tanpa fcm_token
        $driver = $this->user('driver', '1');
        $this->tunai($cso, $driver, 'CashCSO', 50000);

        Sanctum::actingAs($cso);
        $this->postJson('/api/cso/deposits', ['dates' => [now()->toDateString()]])
            ->assertCreated();

        $this->assertDatabaseCount('cso_deposits', 1);
        Queue::assertNotPushed(SendFcmNotification::class);
        // WA ke admin tidak ikut terpengaruh oleh kosongnya token penyetor.
        Queue::assertPushed(SendWhatsAppMessage::class);
    }

    public function test_gateway_wa_kosong_tidak_membungkam_push(): void
    {
        // wa_token & admin_wa_number sengaja TIDAK diisi.
        $cso    = $this->user('cso', '1', 'token-cso-abc');
        $driver = $this->user('driver', '1');
        $this->tunai($cso, $driver, 'CashCSO', 50000);

        Sanctum::actingAs($cso);
        $this->postJson('/api/cso/deposits', ['dates' => [now()->toDateString()]])
            ->assertCreated();

        Queue::assertNotPushed(SendWhatsAppMessage::class);
        // Inilah yang dijaga: push berdiri sendiri, tidak ikut mati bersama WA.
        Queue::assertPushed(SendFcmNotification::class);
    }

    // === Setoran diputuskan ================================================

    public function test_persetujuan_memicu_push_ke_cso(): void
    {
        $cso   = $this->user('cso', '1', 'token-cso-abc');
        $admin = $this->user('admin', '1');

        $deposit = CsoDeposit::create([
            'cso_id' => $cso->id, 'amount' => 250000, 'status' => 'Pending',
            'period_dates' => [now()->toDateString()], 'transactions_count' => 2,
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/cso-deposits/{$deposit->id}/approve")->assertOk();

        Queue::assertPushed(SendFcmNotification::class, function ($job) {
            return $this->prop($job, 'title') === 'Setoran diterima'
                && str_contains($this->prop($job, 'body'), '250.000');
        });
    }

    public function test_penolakan_memicu_push_berisi_alasan(): void
    {
        $cso   = $this->user('cso', '1', 'token-cso-abc');
        $admin = $this->user('admin', '1');

        $deposit = CsoDeposit::create([
            'cso_id' => $cso->id, 'amount' => 250000, 'status' => 'Pending',
            'period_dates' => [now()->toDateString()], 'transactions_count' => 2,
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/cso-deposits/{$deposit->id}/reject", [
            'admin_note' => 'nominal tidak cocok',
        ])->assertOk();

        Queue::assertPushed(SendFcmNotification::class, function ($job) {
            return $this->prop($job, 'title') === 'Setoran ditolak'
                && str_contains($this->prop($job, 'body'), 'nominal tidak cocok');
        });
    }

    // === Pendaftaran token FCM =============================================

    public function test_rute_token_netral_peran_bisa_dipakai_cso(): void
    {
        // Inilah bug yang ditutup: sebelumnya CSO hanya punya
        // /driver/update-fcm-token yang dijaga role:driver, sehingga selalu 403
        // dan token CSO tidak pernah tersimpan.
        $cso = $this->user('cso', '1');

        Sanctum::actingAs($cso);
        $this->postJson('/api/me/fcm-token', ['fcm_token' => 'token-baru'])->assertOk();

        $this->assertSame('token-baru', $cso->fresh()->fcm_token);
    }

    public function test_rute_lama_supir_tetap_berfungsi(): void
    {
        // Aplikasi supir sudah terpasang di HP orang; rute lama tidak boleh
        // ikut hilang saat rute baru ditambahkan.
        $driver = $this->user('driver', '1');

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/update-fcm-token', ['fcm_token' => 'token-lama'])->assertOk();

        $this->assertSame('token-lama', $driver->fresh()->fcm_token);
    }

    // === Bantuan ===========================================================

    /** Baca properti privat job hasil Queue::fake(). */
    private function prop(object $job, string $nama)
    {
        $r = new \ReflectionObject($job);
        if (!$r->hasProperty($nama)) {
            return null;
        }
        $p = $r->getProperty($nama);
        $p->setAccessible(true);
        return $p->getValue($job);
    }

    private function semuaProp(object $job): array
    {
        $hasil = [];
        foreach ((new \ReflectionObject($job))->getProperties() as $p) {
            $p->setAccessible(true);
            $nilai = $p->getValue($job);
            if (is_scalar($nilai) || is_array($nilai)) {
                $hasil[$p->getName()] = $nilai;
            }
        }
        return $hasil;
    }
}
