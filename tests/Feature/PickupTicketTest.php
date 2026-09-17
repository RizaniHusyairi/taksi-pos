<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Booking;
use App\Models\DriverProfile;
use App\Models\DriverQueue;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\FcmService;
use App\Services\QueueHeadsUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Karcis terbit saat supir "SAYA JEMPUT", bukan saat CSO membuat order.
 *
 * Yang dijaga: hanya supir pemilik order yang bisa mengonfirmasi, konfirmasi
 * idempoten & memberi tahu CSO sekali saja, MULAI PERJALANAN dan pergerakan
 * mobil juga menerbitkan karcis, WA ke penumpang pindah dari otomatis menjadi
 * aksi CSO yang dijaga, dan peringatan pra-giliran tidak pernah dobel.
 */
class PickupTicketTest extends TestCase
{
    use RefreshDatabase;

    // Bandara (config/taksi.php) — supaya supir dianggap di dalam area.
    private const LAT = -0.371975;
    private const LNG = 117.257919;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Setting::forgetMemo();
    }

    private function user(string $role, string $suffix = '', array $extra = []): User
    {
        return User::create(array_merge([
            'name'     => ucfirst($role) . ' ' . $suffix,
            'username' => $role . $suffix,
            'email'    => $role . $suffix . '@x.test',
            'password' => bcrypt('rahasia123'),
            'role'     => $role,
            'active'   => true,
        ], $extra));
    }

    private function supir(string $suffix, int $sortOrder = 0, bool $antri = true): User
    {
        $driver = $this->user('driver', $suffix, ['fcm_token' => 'tok-driver-' . $suffix]);
        DriverProfile::create([
            'user_id'             => $driver->id,
            'car_model'           => 'Toyota Avanza',
            'plate_number'        => 'KT 12' . $suffix . ' AB',
            'status'              => 'standby',
            'latitude'            => self::LAT,
            'longitude'           => self::LNG,
            'location_updated_at' => now(),
            'out_of_area_since'   => null,
        ]);
        if ($antri) {
            DriverQueue::create(['user_id' => $driver->id, 'sort_order' => $sortOrder]);
        }
        return $driver;
    }

    /** Order Assigned lewat jalur asli, supaya assigned_lat/lng ikut terisi. */
    private function order(array $tambahan = []): array
    {
        $cso    = $this->user('cso', '1', ['fcm_token' => 'tok-cso']);
        $driver = $this->supir('1');
        $zone   = Zone::create(['name' => 'Zona A', 'price' => 100000]);

        Sanctum::actingAs($cso);
        $this->postJson('/api/cso/process-order', array_merge([
            'driver_id' => $driver->id,
            'zone_id'   => $zone->id,
            'method'    => 'CashCSO',
        ], $tambahan))->assertCreated();

        return [$cso, $driver, Booking::first()];
    }

    private function fcmKe(string $token, string $type): int
    {
        return Queue::pushed(SendFcmNotification::class, function ($job) use ($token, $type) {
            $isi = json_encode((array) $job);
            return str_contains($isi, $token) && str_contains($isi, $type);
        })->count();
    }

    // === Order baru: karcis terkunci ======================================

    public function test_order_baru_belum_menerbitkan_karcis_dan_mencatat_posisi(): void
    {
        [, , $booking] = $this->order();

        $this->assertNull($booking->pickup_confirmed_at);
        $this->assertEqualsWithDelta(self::LAT, (float) $booking->assigned_lat, 0.000001);
    }

    public function test_wa_penumpang_tidak_lagi_terkirim_otomatis(): void
    {
        Setting::updateOrCreate(['key' => 'wa_token'], ['value' => 'wag_uji']);
        Setting::forgetMemo();

        $this->order(['passenger_phone' => '081234567890']);

        // Pesan ke SUPIR tetap memuat nomor penumpang; yang harus absen adalah
        // pesan struk/karcis yang DITUJUKAN ke penumpang.
        Queue::assertNotPushed(SendWhatsAppMessage::class, function ($job) {
            $isi = json_encode((array) $job);
            return str_contains($isi, 'STRUK PEMBAYARAN') || str_contains($isi, 'KARCIS TAKSI');
        });
    }

    // === SAYA JEMPUT =======================================================

    public function test_supir_lain_tidak_bisa_konfirmasi(): void
    {
        [, , $booking] = $this->order();
        $lain = $this->supir('9', 5, false);

        Sanctum::actingAs($lain);
        $this->postJson("/api/driver/bookings/{$booking->id}/pickup")->assertForbidden();

        $this->assertNull($booking->fresh()->pickup_confirmed_at);
    }

    public function test_konfirmasi_menerbitkan_karcis_dan_memberi_tahu_cso_sekali(): void
    {
        [, $driver, $booking] = $this->order();

        Sanctum::actingAs($driver);
        $this->postJson("/api/driver/bookings/{$booking->id}/pickup", ['source' => 'notification'])->assertOk();
        $this->postJson("/api/driver/bookings/{$booking->id}/pickup")->assertOk(); // idempoten

        $fresh = $booking->fresh();
        $this->assertNotNull($fresh->pickup_confirmed_at);
        $this->assertSame('notification', $fresh->pickup_source);
        $this->assertSame('Assigned', $fresh->status);
        $this->assertSame(1, $this->fcmKe('tok-cso', 'ticket_ready'));
    }

    public function test_order_selesai_tidak_bisa_dikonfirmasi(): void
    {
        [, $driver, $booking] = $this->order();
        $booking->update(['status' => 'Completed']);

        Sanctum::actingAs($driver);
        $this->postJson("/api/driver/bookings/{$booking->id}/pickup")->assertStatus(422);
    }

    public function test_mulai_perjalanan_tanpa_jemput_tetap_menerbitkan_karcis(): void
    {
        [, $driver, $booking] = $this->order();

        Sanctum::actingAs($driver);
        $this->postJson("/api/driver/bookings/{$booking->id}/start")->assertOk();

        $fresh = $booking->fresh();
        $this->assertSame('OnTrip', $fresh->status);
        $this->assertNotNull($fresh->pickup_confirmed_at);
    }

    public function test_ganti_supir_mereset_konfirmasi(): void
    {
        [$cso, $driver, $booking] = $this->order();
        Sanctum::actingAs($driver);
        $this->postJson("/api/driver/bookings/{$booking->id}/pickup")->assertOk();

        $baru = $this->supir('2');
        Sanctum::actingAs($cso);
        $this->postJson("/api/cso/bookings/{$booking->id}/change-driver", ['new_driver_id' => $baru->id])
            ->assertOk();

        $this->assertNull($booking->fresh()->pickup_confirmed_at);
    }

    // === Konfirmasi otomatis lewat lokasi ==================================

    public function test_mobil_bergerak_melewati_ambang_menerbitkan_karcis(): void
    {
        [, $driver, $booking] = $this->order();
        Sanctum::actingAs($driver);

        // ~30 m: jitter parkiran, belum dianggap berangkat.
        $this->postJson('/api/driver/location', ['latitude' => self::LAT + 0.00027, 'longitude' => self::LNG]);
        $this->assertNull($booking->fresh()->pickup_confirmed_at);

        // ~110 m: berangkat.
        $this->postJson('/api/driver/location', ['latitude' => self::LAT + 0.001, 'longitude' => self::LNG]);
        $fresh = $booking->fresh();
        $this->assertNotNull($fresh->pickup_confirmed_at);
        $this->assertSame('auto_location', $fresh->pickup_source);
    }

    // === WA karcis manual ==================================================

    public function test_wa_karcis_ditolak_sebelum_supir_berangkat(): void
    {
        Setting::updateOrCreate(['key' => 'wa_token'], ['value' => 'wag_uji']);
        Setting::forgetMemo();
        [$cso, , $booking] = $this->order(['passenger_phone' => '081234567890']);

        Sanctum::actingAs($cso);
        $this->postJson("/api/cso/bookings/{$booking->id}/ticket/whatsapp")->assertStatus(422);
        Queue::assertNotPushed(SendWhatsAppMessage::class, fn ($job) => str_contains(json_encode((array) $job), 'KARCIS TAKSI'));
    }

    public function test_wa_karcis_terkirim_setelah_jemput_dan_dijeda(): void
    {
        Setting::updateOrCreate(['key' => 'wa_token'], ['value' => 'wag_uji']);
        Setting::forgetMemo();
        [$cso, $driver, $booking] = $this->order(['passenger_phone' => '081234567890']);

        Sanctum::actingAs($driver);
        $this->postJson("/api/driver/bookings/{$booking->id}/pickup")->assertOk();

        Sanctum::actingAs($cso);
        $this->postJson("/api/cso/bookings/{$booking->id}/ticket/whatsapp")->assertOk();
        $this->postJson("/api/cso/bookings/{$booking->id}/ticket/whatsapp")->assertStatus(429);

        Queue::assertPushed(SendWhatsAppMessage::class, fn ($job) => str_contains(json_encode((array) $job), 'KARCIS TAKSI'));
        $this->assertNotNull($booking->fresh()->ticket_wa_sent_at);
    }

    public function test_wa_karcis_ditolak_tanpa_nomor_atau_cso_lain(): void
    {
        [$cso, $driver, $booking] = $this->order();
        Sanctum::actingAs($driver);
        $this->postJson("/api/driver/bookings/{$booking->id}/pickup")->assertOk();

        Sanctum::actingAs($cso);
        $this->postJson("/api/cso/bookings/{$booking->id}/ticket/whatsapp")->assertStatus(422);

        Sanctum::actingAs($this->user('cso', '2'));
        $this->postJson("/api/cso/bookings/{$booking->id}/ticket/whatsapp")->assertForbidden();
    }

    public function test_pending_tickets_hanya_milik_cso_sendiri(): void
    {
        [$cso] = $this->order();

        Sanctum::actingAs($cso);
        $this->getJson('/api/cso/pending-tickets')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->user('cso', '2'));
        $this->getJson('/api/cso/pending-tickets')->assertOk()->assertJsonCount(0, 'data');
    }

    // === Payload FCM ======================================================

    public function test_order_baru_dikirim_sebagai_pesan_data_saja(): void
    {
        $order = FcmService::buildMessage('tok', 'Judul', 'Isi', ['type' => 'new_order', 'booking_id' => '5']);
        $this->assertArrayNotHasKey('notification', $order);
        $this->assertSame('Judul', $order['data']['title']);
        $this->assertSame('5', $order['data']['booking_id']);

        $lain = FcmService::buildMessage('tok', 'J', 'I', ['type' => 'queue_heads_up']);
        $this->assertSame('queue_heads_up_channel', $lain['android']['notification']['channel_id']);
    }

    // === Peringatan pra-giliran ============================================

    public function test_peringatan_pra_giliran_tidak_dobel(): void
    {
        $a = $this->supir('1', 0);
        $b = $this->supir('2', 1);
        $c = $this->supir('3', 2);

        $service = app(QueueHeadsUpService::class);
        $service->notify();
        $service->notify();

        $this->assertSame(1, $this->fcmKe('tok-driver-1', 'queue_heads_up'));
        $this->assertSame(1, $this->fcmKe('tok-driver-2', 'queue_heads_up'));
        $this->assertSame(0, $this->fcmKe('tok-driver-3', 'queue_heads_up'));

        // Supir teratas pergi: B naik ke posisi 1 (push baru), C ke posisi 2.
        DriverQueue::where('user_id', $a->id)->delete();
        $service->notify();

        $this->assertSame(2, $this->fcmKe('tok-driver-2', 'queue_heads_up'));
        $this->assertSame(1, $this->fcmKe('tok-driver-3', 'queue_heads_up'));
    }
}
