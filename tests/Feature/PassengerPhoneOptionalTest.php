<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Booking;
use App\Models\DriverProfile;
use App\Models\DriverQueue;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nomor WhatsApp penumpang bersifat OPSIONAL.
 *
 * Penumpang yang tidak punya WhatsApp — atau menolak memberi nomornya — tidak
 * boleh membuat order gagal diproses, karena uangnya sudah diterima dan
 * supirnya sudah menunggu.
 *
 * Yang dijaga di sini ada tiga: order tanpa nomor benar-benar lolos, nomor yang
 * DIISI tetap divalidasi rentangnya, dan nomor yang diisi benar-benar
 * TERSIMPAN — yang terakhir ini penting karena kolomnya dipakai di dalam
 * closure DB::transaction, tempat variabel yang lupa di-`use` akan diam-diam
 * menjadi null tanpa satu pun galat.
 */
class PassengerPhoneOptionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Setting::forgetMemo();
    }

    private function user(string $role, string $suffix = ''): User
    {
        return User::create([
            'name'     => ucfirst($role) . ' ' . $suffix,
            'username' => $role . $suffix,
            'email'    => $role . $suffix . '@x.test',
            'password' => bcrypt('rahasia123'),
            'role'     => $role,
            'active'   => true,
        ]);
    }

    /** Supir siap menerima order: profil standby, di dalam area, lokasi segar. */
    private function supirSiap(): User
    {
        $driver = $this->user('driver', '1');

        DriverProfile::create([
            'user_id'             => $driver->id,
            'car_model'           => 'Toyota Avanza',
            'plate_number'        => 'KT 1234 AB',
            'status'              => 'standby',
            'latitude'            => -0.9,
            'longitude'           => 117.0,
            'location_updated_at' => now(),
            'out_of_area_since'   => null,
        ]);
        DriverQueue::create([
            'user_id'    => $driver->id,
            'sort_order' => 0,
        ]);

        return $driver;
    }

    private function pesan(User $cso, User $driver, Zone $zone, array $tambahan = [])
    {
        Sanctum::actingAs($cso);

        return $this->postJson('/api/cso/process-order', array_merge([
            'driver_id' => $driver->id,
            'zone_id'   => $zone->id,
            'method'    => 'CashCSO',
        ], $tambahan));
    }

    private function siapkan(): array
    {
        return [
            $this->user('cso', '1'),
            $this->supirSiap(),
            Zone::create(['name' => 'Zona A', 'price' => 100000]),
        ];
    }

    // === Boleh kosong ======================================================

    public function test_order_tanpa_nomor_tetap_diproses(): void
    {
        [$cso, $driver, $zone] = $this->siapkan();

        $this->pesan($cso, $driver, $zone)->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
        $this->assertNull(Booking::first()->passenger_phone);
    }

    public function test_nomor_kosong_tersimpan_sebagai_null(): void
    {
        [$cso, $driver, $zone] = $this->siapkan();

        // Aplikasi mengirim string kosong; middleware global
        // ConvertEmptyStringsToNull mengubahnya jadi null, sehingga aturan
        // min:10 dilewati. Kalau middleware itu suatu saat dimatikan, test ini
        // yang akan memberi tahu.
        $this->pesan($cso, $driver, $zone, ['passenger_phone' => ''])
            ->assertCreated();

        $this->assertNull(Booking::first()->passenger_phone);
    }

    // === Bila diisi, tetap divalidasi ======================================

    public function test_nomor_terlalu_pendek_ditolak(): void
    {
        [$cso, $driver, $zone] = $this->siapkan();

        $this->pesan($cso, $driver, $zone, ['passenger_phone' => '081234567'])  // 9 digit
            ->assertStatus(422)
            ->assertJsonValidationErrors('passenger_phone');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_nomor_terlalu_panjang_ditolak(): void
    {
        [$cso, $driver, $zone] = $this->siapkan();

        $this->pesan($cso, $driver, $zone, ['passenger_phone' => '0812345678901234']) // 16
            ->assertStatus(422)
            ->assertJsonValidationErrors('passenger_phone');
    }

    // === Nomor yang diisi harus benar-benar tersimpan ======================

    public function test_nomor_yang_diisi_tersimpan(): void
    {
        [$cso, $driver, $zone] = $this->siapkan();

        $this->pesan($cso, $driver, $zone, ['passenger_phone' => '081234567890'])
            ->assertCreated();

        // Penjaga terhadap variabel yang lupa di-`use` pada closure
        // DB::transaction: kalau itu terjadi, kolomnya diam-diam null padahal
        // permintaannya berhasil 201.
        $this->assertSame('081234567890', Booking::first()->passenger_phone);
    }

    // === Pesan WhatsApp ====================================================

    public function test_wa_ke_penumpang_hanya_saat_nomor_ada(): void
    {
        Setting::updateOrCreate(['key' => 'wa_token'], ['value' => 'wag_uji.rahasia']);
        Setting::forgetMemo();

        [$cso, $driver, $zone] = $this->siapkan();

        $this->pesan($cso, $driver, $zone)->assertCreated();

        // Melarang SEMUA job WhatsApp keliru: pesan ke SUPIR memang tetap
        // terkirim. Yang harus absen adalah pesan struk ke penumpang —
        // dikenali dari judulnya, karena hanya pesan itu yang memuat link
        // struk sekaligus kunci penilaian.
        Queue::assertPushed(SendWhatsAppMessage::class, function ($job) {
            return !str_contains(json_encode($this->propJob($job)), 'STRUK PEMBAYARAN');
        });
        Queue::assertNotPushed(SendWhatsAppMessage::class, function ($job) {
            return str_contains(json_encode($this->propJob($job)), 'STRUK PEMBAYARAN');
        });
    }

    public function test_pesan_ke_supir_tidak_memuat_baris_penumpang_kosong(): void
    {
        Setting::updateOrCreate(['key' => 'wa_token'], ['value' => 'wag_uji.rahasia']);
        Setting::forgetMemo();

        [$cso, $driver, $zone] = $this->siapkan();
        // Supir perlu nomor agar jalur WA-nya berjalan.
        $driver->forceFill(['phone_number' => '628999888777'])->save();

        $this->pesan($cso, $driver, $zone)->assertCreated();

        Queue::assertPushed(SendWhatsAppMessage::class, function ($job) {
            $isi = json_encode($this->propJob($job));
            // "Penumpang:" yang menggantung tanpa isi terbaca seperti pesan rusak.
            return !str_contains($isi, 'Penumpang:');
        });
    }

    private function propJob(object $job): array
    {
        $hasil = [];
        foreach ((new \ReflectionObject($job))->getProperties() as $p) {
            $p->setAccessible(true);
            $v = $p->getValue($job);
            if (is_scalar($v)) {
                $hasil[$p->getName()] = $v;
            }
        }
        return $hasil;
    }
}
