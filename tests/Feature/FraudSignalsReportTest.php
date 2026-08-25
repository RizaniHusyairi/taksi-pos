<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DriverActivity;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Laporan sinyal kecurangan untuk admin.
 *
 * Ketiganya adalah HEURISTIK, dan test di sini menguji kedua arah dengan bobot
 * yang sama: sinyal yang benar harus muncul, dan yang wajar harus TIDAK muncul.
 * Laporan yang penuh alarm palsu akan berhenti dibaca dalam seminggu, dan saat
 * itu terjadi ia lebih berbahaya daripada tidak ada laporan sama sekali —
 * karena memberi rasa aman yang keliru.
 */
class FraudSignalsReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // firstOrCreate: beberapa test memanggil laporan() lebih dari sekali.
        return User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Admin', 'email' => 'a@x.test',
                'password' => bcrypt('x'), 'role' => 'admin', 'active' => true,
            ]
        );
    }

    private function cso(string $suffix): User
    {
        return User::create([
            'name' => 'CSO ' . $suffix, 'username' => 'cso' . $suffix,
            'email' => "cso{$suffix}@x.test", 'password' => bcrypt('x'),
            'role' => 'cso', 'active' => true,
        ]);
    }

    private function driver(string $suffix): User
    {
        $u = User::create([
            'name' => 'Supir ' . $suffix, 'username' => 'sup' . $suffix,
            'email' => "sup{$suffix}@x.test", 'password' => bcrypt('x'),
            'role' => 'driver', 'active' => true,
        ]);
        DriverProfile::create([
            'user_id' => $u->id, 'car_model' => 'Avanza', 'plate_number' => 'KT ' . $suffix,
        ]);
        return $u;
    }

    private function booking(User $cso, User $driver, array $extra = []): Booking
    {
        return Booking::create(array_merge([
            'cso_id'    => $cso->id,
            'driver_id' => $driver->id,
            'zone_id'   => Zone::firstOrCreate(['name' => 'Kota'], ['price' => 100000])->id,
            'price'     => 100000,
            'status'    => 'Completed',
        ], $extra));
    }

    private function laporan(array $query = []): array
    {
        Sanctum::actingAs($this->admin());
        return $this->getJson('/api/admin/reports/fraud-signals?' . http_build_query($query))
            ->assertOk()->json();
    }

    // === A. Override antrian ==============================================

    public function test_rasio_override_dihitung_per_cso(): void
    {
        $csoA = $this->cso('a');
        $csoB = $this->cso('b');
        $d1 = $this->driver('1');
        $d2 = $this->driver('2');

        // CSO A: 4 order, 3 di antaranya melewati giliran (75%).
        foreach (range(1, 3) as $i) {
            $this->booking($csoA, $d1, ['queue_override' => true, 'skipped_driver_id' => $d2->id]);
        }
        $this->booking($csoA, $d1);

        // CSO B: 4 order, tidak pernah melewati giliran.
        foreach (range(1, 4) as $i) {
            $this->booking($csoB, $d2);
        }

        $hasil = $this->laporan()['queue_overrides'];

        // Terurut dari rasio tertinggi.
        $this->assertSame('CSO a', $hasil['csos'][0]['name']);
        $this->assertEquals(75.0, $hasil['csos'][0]['override_rate']);
        $this->assertEquals(0.0, $hasil['csos'][1]['override_rate']);

        // Rata-rata seluruh CSO — pembanding yang membuat angka 75% bermakna.
        $this->assertEquals(37.5, $hasil['average_rate']);
    }

    /**
     * Pola "selalu mendahulukan orang yang sama, melewati orang yang sama"
     * jauh lebih kuat daripada sekadar jumlah override.
     */
    public function test_pasangan_supir_yang_paling_sering_berulang_ditampilkan(): void
    {
        $cso = $this->cso('a');
        $favorit = $this->driver('favorit');
        $korban  = $this->driver('korban');

        foreach (range(1, 3) as $i) {
            $this->booking($cso, $favorit, [
                'queue_override' => true, 'skipped_driver_id' => $korban->id,
            ]);
        }

        $pair = $this->laporan()['queue_overrides']['csos'][0]['top_pair'];

        $this->assertSame('Supir favorit', $pair['favored_driver']);
        $this->assertSame('Supir korban', $pair['skipped_driver']);
        $this->assertSame(3, $pair['count']);
    }

    public function test_cso_tanpa_override_tidak_punya_pasangan(): void
    {
        $cso = $this->cso('a');
        $this->booking($cso, $this->driver('1'));

        $this->assertNull($this->laporan()['queue_overrides']['csos'][0]['top_pair']);
    }

    // === B. Nomor penumpang berulang ======================================

    public function test_nomor_berulang_muncul_dengan_daftar_cso_pemakainya(): void
    {
        $csoA = $this->cso('a');
        $d = $this->driver('1');

        foreach (range(1, 4) as $i) {
            $this->booking($csoA, $d, ['passenger_phone' => '081200000001']);
        }
        // Nomor yang cuma dipakai sekali tidak boleh muncul.
        $this->booking($csoA, $d, ['passenger_phone' => '081299999999']);

        $hasil = $this->laporan()['repeated_phones'];

        $this->assertCount(1, $hasil);
        $this->assertSame('081200000001', $hasil[0]['phone']);
        $this->assertSame(4, $hasil[0]['count']);
        $this->assertSame(['CSO a'], $hasil[0]['csos']);
    }

    /**
     * Satu nomor dipakai BANYAK CSO itu wajar (pelanggan tetap). Yang
     * mencurigakan adalah nomor yang selalu diketik CSO yang sama — jadi
     * jumlah CSO wajib ikut dilaporkan, bukan cuma jumlah pemakaian.
     */
    public function test_jumlah_cso_pemakai_ikut_dilaporkan(): void
    {
        $d = $this->driver('1');
        foreach (['a', 'b', 'c'] as $s) {
            $this->booking($this->cso($s), $d, ['passenger_phone' => '081200000002']);
        }

        $hasil = $this->laporan()['repeated_phones'][0];

        $this->assertSame(3, $hasil['count']);
        $this->assertSame(3, $hasil['cso_count']);
    }

    public function test_ambang_pengulangan_bisa_diatur(): void
    {
        $cso = $this->cso('a');
        $d = $this->driver('1');
        $this->booking($cso, $d, ['passenger_phone' => '0812000003']);
        $this->booking($cso, $d, ['passenger_phone' => '0812000003']);

        // Ambang bawaan 3 -> belum muncul.
        $this->assertCount(0, $this->laporan()['repeated_phones']);
        // Ambang 2 -> muncul.
        $this->assertCount(1, $this->laporan(['phone_min' => 2])['repeated_phones']);
    }

    // === C. Keluar area tanpa order =======================================

    /**
     * `created_at` TIDAK fillable di DriverActivity, jadi harus di-forceFill —
     * kalau hanya dioper ke create() ia diam-diam jadi now(), dan seluruh test
     * durasi di bawah ini akan lulus palsu karena selisihnya selalu 0 menit.
     */
    private function jejak(User $driver, string $keluar, string $kembali): void
    {
        foreach ([['AIRPORT_EXIT', $keluar], ['AIRPORT_ENTER', $kembali]] as [$tipe, $waktu]) {
            DriverActivity::create([
                'user_id' => $driver->id,
                'activity_type' => $tipe,
                'description' => $tipe,
            ])->forceFill(['created_at' => $waktu])->save();
        }
    }

    public function test_keluar_area_tanpa_order_terdeteksi(): void
    {
        $d = $this->driver('1');
        $this->jejak($d, now()->subHours(3)->toDateTimeString(), now()->subHours(2)->toDateTimeString());

        $hasil = $this->laporan()['unreported_trips'];

        $this->assertCount(1, $hasil);
        $this->assertSame($d->id, $hasil[0]['id']);
        $this->assertSame(1, $hasil[0]['trips']);
        $this->assertSame(60, $hasil[0]['total_minutes']);
    }

    /**
     * Supir yang JUJUR melaporkan trip mandirinya (tombol "Dapat Penumpang
     * Sendiri") membuat booking — dan booking itu harus menutupi jejaknya.
     * Kalau tidak, laporan ini justru menghukum kejujuran.
     */
    public function test_trip_yang_dilaporkan_tidak_muncul(): void
    {
        $d = $this->driver('1');
        $keluar  = now()->subHours(3);
        $kembali = now()->subHours(2);
        $this->jejak($d, $keluar->toDateTimeString(), $kembali->toDateTimeString());

        $b = $this->booking($this->cso('a'), $d, ['zone_id' => null, 'manual_destination' => 'Kota']);
        $b->forceFill([
            'created_at' => $keluar->copy()->subMinutes(5),
            'updated_at' => $kembali->copy()->subMinutes(5),
        ])->save();

        $this->assertCount(0, $this->laporan()['unreported_trips']);
    }

    /** Order biasa dari CSO juga menutupi jejaknya. */
    public function test_order_biasa_juga_menutupi_jejak(): void
    {
        $d = $this->driver('1');
        $keluar  = now()->subHours(3);
        $kembali = now()->subHours(2);
        $this->jejak($d, $keluar->toDateTimeString(), $kembali->toDateTimeString());

        $b = $this->booking($this->cso('a'), $d);
        $b->forceFill([
            'created_at' => $keluar->copy()->subMinutes(2),
            'updated_at' => $kembali->copy(),
        ])->save();

        $this->assertCount(0, $this->laporan()['unreported_trips']);
    }

    /** Keluar sebentar = beli makan / menyeberang batas geofence, bukan trip. */
    public function test_kepergian_terlalu_singkat_diabaikan(): void
    {
        $d = $this->driver('1');
        $this->jejak($d, now()->subMinutes(40)->toDateTimeString(), now()->subMinutes(35)->toDateTimeString());

        $this->assertCount(0, $this->laporan()['unreported_trips']);
    }

    /** Pergi seharian = pulang, bukan diam-diam mengantar penumpang. */
    public function test_kepergian_terlalu_lama_diabaikan(): void
    {
        $d = $this->driver('1');
        $this->jejak($d, now()->subHours(20)->toDateTimeString(), now()->subHours(10)->toDateTimeString());

        $this->assertCount(0, $this->laporan()['unreported_trips']);
    }

    /** ENTER pertama hari itu (tanpa EXIT sebelumnya) = supir baru datang. */
    public function test_kedatangan_pertama_bukan_kepergian(): void
    {
        $d = $this->driver('1');
        DriverActivity::create([
            'user_id' => $d->id, 'activity_type' => 'AIRPORT_ENTER',
            'description' => 'Masuk area bandara', 'created_at' => now()->subHours(5),
        ]);

        $this->assertCount(0, $this->laporan()['unreported_trips']);
    }

    public function test_kepergian_berulang_diringkas_per_supir(): void
    {
        $d = $this->driver('1');
        $this->jejak($d, now()->subHours(8)->toDateTimeString(), now()->subHours(7)->toDateTimeString());
        $this->jejak($d, now()->subHours(5)->toDateTimeString(), now()->subHours(4)->toDateTimeString());

        $hasil = $this->laporan()['unreported_trips'];

        $this->assertCount(1, $hasil);
        $this->assertSame(2, $hasil[0]['trips']);
        $this->assertSame(120, $hasil[0]['total_minutes']);
    }

    // === Umum =============================================================

    public function test_rentang_tanggal_dihormati(): void
    {
        $cso = $this->cso('a');
        $d = $this->driver('1');
        $lama = $this->booking($cso, $d, ['passenger_phone' => '081200000001']);
        $lama->forceFill(['created_at' => now()->subMonths(3)])->save();

        $hasil = $this->laporan([
            'date_from' => now()->subMonths(3)->subDay()->toDateString(),
            'date_to'   => now()->subMonths(3)->addDay()->toDateString(),
        ]);

        $this->assertSame(1, $hasil['queue_overrides']['csos'][0]['total_orders']);

        // Di luar rentang -> CSO itu tidak muncul sama sekali.
        $kini = $this->laporan(['date_from' => now()->toDateString()]);
        $this->assertCount(0, $kini['queue_overrides']['csos']);
    }

    public function test_endpoint_hanya_untuk_admin(): void
    {
        Sanctum::actingAs($this->cso('a'));
        $this->getJson('/api/admin/reports/fraud-signals')->assertStatus(403);
    }

    public function test_laporan_kosong_tidak_error(): void
    {
        $hasil = $this->laporan();

        $this->assertSame([], $hasil['queue_overrides']['csos']);
        $this->assertSame([], $hasil['repeated_phones']);
        $this->assertSame([], $hasil['unreported_trips']);
        $this->assertEquals(0.0, $hasil['queue_overrides']['average_rate']);
    }
}
