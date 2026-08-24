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
 * Gerbang anti fake GPS pada endpoint lokasi supir.
 *
 * Yang diuji di sini bukan cuma "ditolak atau tidak", tapi EFEK SAMPINGNYA:
 * fix yang dicurigai palsu tidak boleh menyegarkan bukti kehadiran
 * (`location_updated_at`) dan tidak boleh menggeser posisi tersimpan. Itulah
 * yang membuat pemalsu tersapu sendiri oleh `queue:sweep-stale`, dan justru
 * bagian yang paling gampang rusak tanpa disadari saat kode ini disentuh lagi.
 */
class LocationSpoofDetectionTest extends TestCase
{
    use RefreshDatabase;

    // Titik bandara (config/taksi.php) dan satu titik ~33 km di luarnya.
    private const APT_LAT = -0.371975;
    private const APT_LNG = 117.257919;
    private const JAUH_LAT = -0.371975;
    private const JAUH_LNG = 117.557919;

    private function driver(array $profil = []): User
    {
        $user = User::create([
            'name' => 'Supir', 'username' => 'supir', 'email' => 's@x.test',
            'password' => bcrypt('x'), 'role' => 'driver', 'active' => true,
        ]);

        DriverProfile::create(array_merge([
            'user_id'             => $user->id,
            'car_model'           => 'Avanza',
            'plate_number'        => 'KT 1234 AB',
            'line_number'         => 1,
            'status'              => 'standby',
            'latitude'            => self::APT_LAT,
            'longitude'           => self::APT_LNG,
            'location_updated_at' => now()->subSeconds(60),
            'last_in_area'        => true,
        ], $profil));

        Sanctum::actingAs($user);

        return $user;
    }

    private function ping(array $data)
    {
        return $this->postJson('/api/driver/location', $data);
    }

    // === Flag mock provider dari Android ==================================

    public function test_fix_bertanda_mock_ditolak_dan_supir_dikeluarkan_dari_antrian(): void
    {
        $user = $this->driver();
        DriverQueue::create([
            'user_id' => $user->id, 'sort_order' => 0,
            'latitude' => self::APT_LAT, 'longitude' => self::APT_LNG,
        ]);

        $this->ping([
            'latitude'  => self::APT_LAT,
            'longitude' => self::APT_LNG,
            'is_mocked' => true,
        ])
            ->assertStatus(422)
            ->assertJson(['spoof_detected' => true, 'reason' => 'mock_provider']);

        $profil = $user->driverProfile->fresh();

        $this->assertSame(1, (int) $profil->spoof_strikes);
        $this->assertSame('mock_provider', $profil->last_spoof_reason);
        $this->assertSame('offline', $profil->status);
        // Auto-join dimatikan: supir harus menekan "Masuk Antrian" secara sadar.
        $this->assertTrue((bool) $profil->auto_join_blocked);
        $this->assertSame(0, DriverQueue::where('user_id', $user->id)->count());
    }

    public function test_join_manual_dengan_lokasi_mock_ditolak(): void
    {
        $user = $this->driver(['status' => 'offline']);

        $this->postJson('/api/driver/status', [
            'action'    => 'join',
            'latitude'  => self::APT_LAT,
            'longitude' => self::APT_LNG,
            'is_mocked' => true,
        ])
            ->assertStatus(422)
            ->assertJson(['spoof_detected' => true]);

        $this->assertSame(0, DriverQueue::where('user_id', $user->id)->count());
        $this->assertSame(1, (int) $user->driverProfile->fresh()->spoof_strikes);
    }

    public function test_fix_bertanda_tidak_mock_diterima_normal(): void
    {
        $user = $this->driver();

        $this->ping([
            'latitude'  => self::APT_LAT,
            'longitude' => self::APT_LNG,
            'is_mocked' => false,
        ])->assertOk();

        $this->assertSame(0, (int) $user->driverProfile->fresh()->spoof_strikes);
    }

    // === Deteksi teleport (murni sisi server) =============================

    /**
     * Inti pertahanan yang TIDAK bisa dimatikan klien: APK modifikasi boleh
     * saja tidak pernah mengirim `is_mocked`, lompatannya tetap terhitung.
     */
    public function test_lompatan_mustahil_ditolak_walau_tanpa_flag_mock(): void
    {
        $user = $this->driver();

        // ~33 km dalam 60 detik ≈ 2.000 km/jam.
        $this->ping(['latitude' => self::JAUH_LAT, 'longitude' => self::JAUH_LNG])
            ->assertStatus(422)
            ->assertJson(['spoof_detected' => true, 'reason' => 'teleport']);

        $this->assertSame('teleport', $user->driverProfile->fresh()->last_spoof_reason);
    }

    /**
     * Properti terpenting: fix yang ditolak tidak menyegarkan bukti kehadiran
     * dan tidak menggeser posisi tersimpan. Tanpa ini, pemalsu tetap terlihat
     * "hadir" oleh CSO dan tidak akan pernah tersapu `queue:sweep-stale`.
     */
    public function test_fix_yang_ditolak_tidak_menyegarkan_bukti_kehadiran(): void
    {
        $user = $this->driver();
        $sebelum = $user->driverProfile->fresh();

        $this->ping(['latitude' => self::JAUH_LAT, 'longitude' => self::JAUH_LNG])
            ->assertStatus(422);

        $sesudah = $user->driverProfile->fresh();

        $this->assertEquals(
            $sebelum->location_updated_at->timestamp,
            $sesudah->location_updated_at->timestamp,
            'location_updated_at tidak boleh disegarkan oleh fix yang ditolak.'
        );
        $this->assertEquals((float) $sebelum->latitude, (float) $sesudah->latitude);
        $this->assertEquals((float) $sebelum->longitude, (float) $sesudah->longitude);
        // Breadcrumb rute pun tidak boleh tercemar titik palsu.
        $this->assertSame(0, \App\Models\DriverLocationLog::where('user_id', $user->id)->count());
    }

    /**
     * Antrian TIDAK diutak-atik untuk kasus teleport — buktinya tak langsung
     * dan GPS rusak bisa menghasilkannya. Hukumannya cukup lewat mekanisme
     * yang sudah ada (fix ditolak → basi → tersapu), bukan tendangan langsung.
     */
    public function test_teleport_tidak_langsung_menendang_dari_antrian(): void
    {
        $user = $this->driver();
        DriverQueue::create([
            'user_id' => $user->id, 'sort_order' => 0,
            'latitude' => self::APT_LAT, 'longitude' => self::APT_LNG,
        ]);

        $this->ping(['latitude' => self::JAUH_LAT, 'longitude' => self::JAUH_LNG])
            ->assertStatus(422);

        $this->assertSame(1, DriverQueue::where('user_id', $user->id)->count());
        $this->assertFalse((bool) $user->driverProfile->fresh()->auto_join_blocked);
    }

    // === Anti salah tuduh =================================================

    /**
     * Supir yang benar-benar ngebut tidak boleh dituduh. 33 km dalam 20 menit
     * ≈ 100 km/jam — cepat, tapi jauh di bawah ambang 300 km/jam.
     */
    public function test_perjalanan_cepat_tapi_wajar_tidak_dituduh(): void
    {
        $user = $this->driver(['location_updated_at' => now()->subMinutes(20)]);

        $this->ping(['latitude' => self::JAUH_LAT, 'longitude' => self::JAUH_LNG])
            ->assertOk();

        $this->assertSame(0, (int) $user->driverProfile->fresh()->spoof_strikes);
    }

    /**
     * Jitter GPS: dua fix berdekatan waktu yang meleset beberapa ratus meter
     * menghasilkan kecepatan besar secara matematis, tapi jaraknya di bawah
     * ambang minimum sehingga tidak pernah dianggap teleport.
     */
    public function test_jitter_gps_jarak_dekat_tidak_dianggap_teleport(): void
    {
        $user = $this->driver(['location_updated_at' => now()->subSeconds(6)]);

        // ~0,5 km dalam 6 detik = 300 km/jam, tapi < teleport_min_km (3 km).
        $this->ping([
            'latitude'  => self::APT_LAT + 0.0045,
            'longitude' => self::APT_LNG,
        ])->assertOk();

        $this->assertSame(0, (int) $user->driverProfile->fresh()->spoof_strikes);
    }

    /**
     * Supir yang aplikasinya mati berjam-jam lalu dibuka lagi di rumah bukan
     * pemalsu — selisih waktunya besar, kecepatannya wajar.
     */
    public function test_aplikasi_lama_mati_lalu_hidup_lagi_tidak_dituduh(): void
    {
        $user = $this->driver(['location_updated_at' => now()->subHours(6)]);

        $this->ping(['latitude' => self::JAUH_LAT, 'longitude' => self::JAUH_LNG])
            ->assertOk();

        $this->assertSame(0, (int) $user->driverProfile->fresh()->spoof_strikes);
    }

    /**
     * Supir baru (belum punya fix tersimpan) tidak punya pembanding — apa pun
     * koordinat pertamanya harus diterima, bukan langsung dicurigai.
     */
    public function test_supir_tanpa_baseline_diterima(): void
    {
        $user = $this->driver([
            'latitude'            => null,
            'longitude'           => null,
            'location_updated_at' => null,
        ]);

        $this->ping(['latitude' => self::APT_LAT, 'longitude' => self::APT_LNG])
            ->assertOk();

        $this->assertSame(0, (int) $user->driverProfile->fresh()->spoof_strikes);
    }

    /**
     * (0,0) adalah sentinel "belum ada fix" dari pre-fill rotasi harian, bukan
     * lokasi nyata di Teluk Guinea. Memakainya sebagai baseline akan menuduh
     * setiap supir yang baru datang.
     */
    public function test_baseline_nol_nol_tidak_dipakai_menuduh(): void
    {
        $user = $this->driver([
            'latitude'            => 0,
            'longitude'           => 0,
            'location_updated_at' => now()->subSeconds(30),
        ]);

        $this->ping(['latitude' => self::APT_LAT, 'longitude' => self::APT_LNG])
            ->assertOk();

        $this->assertSame(0, (int) $user->driverProfile->fresh()->spoof_strikes);
    }

    // === Kompatibilitas & visibilitas admin ===============================

    /** Aplikasi versi lama tidak mengirim `is_mocked` — jangan sampai rusak. */
    public function test_aplikasi_lama_tanpa_flag_tetap_dilayani(): void
    {
        $this->driver();

        $this->ping(['latitude' => self::APT_LAT, 'longitude' => self::APT_LNG])
            ->assertOk();
    }

    /**
     * Flag ini cuma PETUNJUK; ping lokasi adalah denyut nadi antrian. Bentuk
     * nilai yang tidak lazim (klien form-encoded mengirim string "false", atau
     * sampah) tidak boleh menjatuhkan seluruh ping — cukup dianggap "tidak
     * tahu". Rule `boolean` bawaan Laravel menolak string "false", dan itu
     * pernah membuat ping wajar balas 422 saat diuji dengan curl.
     *
     * @dataProvider bentukFlagMock
     */
    public function test_flag_mock_dibaca_longgar($nilai, bool $harusDitolak): void
    {
        $user = $this->driver();

        $respons = $this->ping([
            'latitude'  => self::APT_LAT,
            'longitude' => self::APT_LNG,
            'is_mocked' => $nilai,
        ]);

        if ($harusDitolak) {
            $respons->assertStatus(422)->assertJson(['spoof_detected' => true]);
        } else {
            $respons->assertOk();
            $this->assertSame(0, (int) $user->driverProfile->fresh()->spoof_strikes);
        }
    }

    public static function bentukFlagMock(): array
    {
        return [
            'bool true'      => [true,    true],
            'bool false'     => [false,   false],
            'string "true"'  => ['true',  true],
            'string "false"' => ['false', false],
            'angka 1'        => [1,       true],
            'angka 0'        => [0,       false],
            'string "1"'     => ['1',     true],
            'string "0"'     => ['0',     false],
            // Tidak bisa dibaca = "tidak tahu", bukan tuduhan dan bukan error.
            'sampah'         => ['entah', false],
            'kosong'         => ['',      false],
        ];
    }

    /** Mendeteksi tanpa ada yang bisa menindak sama saja dengan tidak mendeteksi. */
    public function test_admin_bisa_melihat_daftar_pantau_spoof(): void
    {
        $supir = $this->driver();
        $this->ping([
            'latitude' => self::APT_LAT, 'longitude' => self::APT_LNG, 'is_mocked' => true,
        ])->assertStatus(422);

        $admin = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'active' => true,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/driver-locations')
            ->assertOk()
            ->assertJsonPath('spoof_watch.0.id', $supir->id)
            ->assertJsonPath('spoof_watch.0.strikes', 1)
            ->assertJsonPath('spoof_watch.0.last_reason', 'mock_provider');
    }

    public function test_strike_bertambah_setiap_pelanggaran(): void
    {
        $user = $this->driver();

        foreach (range(1, 3) as $n) {
            $this->ping([
                'latitude' => self::APT_LAT, 'longitude' => self::APT_LNG, 'is_mocked' => true,
            ])->assertStatus(422);

            $this->assertSame($n, (int) $user->driverProfile->fresh()->spoof_strikes);
        }
    }
}
