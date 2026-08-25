<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Laporan Pendapatan admin.
 *
 * Yang paling dijaga di sini adalah PERHITUNGAN POTONGAN. Versi lama laporan
 * memakai `total * commission_rate` untuk semua transaksi, padahal order manual
 * (tanpa zona) sebenarnya dikenai tarif FLAT. Akibatnya angka di laporan admin
 * tidak pernah cocok dengan tagihan setoran yang dilihat supir — selisih yang
 * kecil, konsisten, dan justru karena itu sulit disadari.
 */
class RevenueReportTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = 0.2;
    private const FLAT = 10000;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['key' => 'commission_rate'], ['value' => self::RATE]);
        Setting::updateOrCreate(['key' => 'manual_fee_flat'], ['value' => self::FLAT]);
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

    /** Satu order + transaksinya. $zone null = order manual (tanpa zona). */
    private function order(
        User $cso, User $driver, ?Zone $zone, float $amount,
        string $method = 'CashCSO', ?string $tanggal = null, string $status = 'Completed'
    ): Transaction {
        $waktu = $tanggal ? \Carbon\Carbon::parse($tanggal . ' 10:00:00') : now();

        $booking = Booking::create([
            'cso_id'    => $cso->id,
            'driver_id' => $driver->id,
            'zone_id'   => $zone?->id,
            'price'     => $amount,
            'status'    => $status,
            'manual_destination' => $zone ? null : 'Tujuan manual',
        ]);
        $booking->forceFill(['created_at' => $waktu, 'updated_at' => $waktu])->save();

        $trx = Transaction::create([
            'booking_id'    => $booking->id,
            'method'        => $method,
            'amount'        => $amount,
            'payout_status' => 'Unpaid',
        ]);
        $trx->forceFill(['created_at' => $waktu, 'updated_at' => $waktu])->save();

        return $trx;
    }

    private function laporan(array $query = []): array
    {
        Sanctum::actingAs($this->user('admin'));

        return $this->getJson('/api/admin/reports/revenue?' . http_build_query($query))
            ->assertOk()
            ->json();
    }

    // === Potongan: inti perbaikan ==========================================

    public function test_order_manual_dipotong_flat_bukan_persentase(): void
    {
        $cso    = $this->user('cso', '1');
        $driver = $this->user('driver', '1');
        $hari   = now()->toDateString();

        // Satu-satunya transaksi: order manual Rp 100.000.
        // Salah (cara lama) : 100.000 * 20%  = 20.000
        // Benar             : tarif flat     = 10.000
        $this->order($cso, $driver, null, 100000, 'CashCSO', $hari);

        $r = $this->laporan(['date_from' => $hari, 'date_to' => $hari]);

        $this->assertSame(100000.0, (float) $r['summary']['gross']);
        $this->assertSame((float) self::FLAT, (float) $r['summary']['commission']);
        $this->assertSame(90000.0, (float) $r['summary']['net_driver']);
    }

    public function test_order_berzona_dipotong_persentase(): void
    {
        $cso    = $this->user('cso', '1');
        $driver = $this->user('driver', '1');
        $zone   = Zone::create(['name' => 'Zona A', 'price' => 200000]);
        $hari   = now()->toDateString();

        $this->order($cso, $driver, $zone, 200000, 'CashCSO', $hari);

        $r = $this->laporan(['date_from' => $hari, 'date_to' => $hari]);

        $this->assertSame(40000.0, (float) $r['summary']['commission']);
    }

    public function test_campuran_manual_dan_berzona_dijumlah_per_baris(): void
    {
        $cso    = $this->user('cso', '1');
        $driver = $this->user('driver', '1');
        $zone   = Zone::create(['name' => 'Zona A', 'price' => 200000]);
        $hari   = now()->toDateString();

        $this->order($cso, $driver, $zone, 200000, 'CashCSO', $hari);   // 40.000
        $this->order($cso, $driver, null, 100000, 'QRIS', $hari);       // 10.000 flat

        $r = $this->laporan(['date_from' => $hari, 'date_to' => $hari]);

        $this->assertSame(300000.0, (float) $r['summary']['gross']);
        $this->assertSame(50000.0, (float) $r['summary']['commission']);
        // Cara lama akan menghasilkan 300.000 * 20% = 60.000.
        $this->assertNotSame(60000.0, (float) $r['summary']['commission']);
    }

    // === Konsistensi angka =================================================

    public function test_penjumlahan_per_metode_sama_dengan_total_kotor(): void
    {
        $cso    = $this->user('cso', '1');
        $driver = $this->user('driver', '1');
        $zone   = Zone::create(['name' => 'Zona A', 'price' => 50000]);
        $hari   = now()->toDateString();

        $this->order($cso, $driver, $zone, 50000, 'CashCSO', $hari);
        $this->order($cso, $driver, $zone, 70000, 'QRIS', $hari);
        $this->order($cso, $driver, $zone, 30000, 'CashDriver', $hari);

        $r = $this->laporan(['date_from' => $hari, 'date_to' => $hari]);

        $this->assertSame(
            (float) $r['summary']['gross'],
            (float) collect($r['by_method'])->sum('total')
        );
        // Ketiga metode selalu hadir walau nol, agar tata letak kartunya stabil.
        $this->assertCount(3, $r['by_method']);
    }

    public function test_rentang_tanggal_menyaring_transaksi(): void
    {
        $cso    = $this->user('cso', '1');
        $driver = $this->user('driver', '1');
        $zone   = Zone::create(['name' => 'Zona A', 'price' => 50000]);

        $this->order($cso, $driver, $zone, 50000, 'CashCSO', now()->toDateString());
        $this->order($cso, $driver, $zone, 90000, 'CashCSO', now()->subDays(10)->toDateString());

        $r = $this->laporan([
            'date_from' => now()->subDays(2)->toDateString(),
            'date_to'   => now()->toDateString(),
        ]);

        $this->assertSame(50000.0, (float) $r['summary']['gross']);
        $this->assertSame(1, $r['summary']['trx_count']);
    }

    public function test_hari_kosong_tetap_muncul_di_tren(): void
    {
        $cso    = $this->user('cso', '1');
        $driver = $this->user('driver', '1');
        $zone   = Zone::create(['name' => 'Zona A', 'price' => 50000]);

        $this->order($cso, $driver, $zone, 50000, 'CashCSO', now()->toDateString());

        $r = $this->laporan([
            'date_from' => now()->subDays(4)->toDateString(),
            'date_to'   => now()->toDateString(),
        ]);

        // Lima titik, bukan satu: grafik yang melewati hari kosong membuat tren
        // terlihat lebih mulus daripada kenyataannya.
        $this->assertSame(5, $r['range']['days']);
        $this->assertCount(5, $r['daily']);
        $this->assertSame(0.0, (float) $r['daily'][0]['total']);
    }

    public function test_order_manual_muncul_sebagai_baris_zona_tersendiri(): void
    {
        $cso    = $this->user('cso', '1');
        $driver = $this->user('driver', '1');
        $hari   = now()->toDateString();

        $this->order($cso, $driver, null, 75000, 'CashCSO', $hari);

        $r = $this->laporan(['date_from' => $hari, 'date_to' => $hari]);

        $manual = collect($r['by_zone'])->firstWhere('zone_id', null);
        $this->assertNotNull($manual, 'Order tanpa zona harus tetap terlihat di rincian zona.');
        $this->assertSame(75000.0, (float) $manual['total']);
    }

    // === Posisi kas ========================================================

    public function test_posisi_kas_memakai_definisi_yang_sama_dengan_aplikasi(): void
    {
        $cso    = $this->user('cso', '1');
        $driver = $this->user('driver', '1');
        $zone   = Zone::create(['name' => 'Zona A', 'price' => 50000]);

        $this->order($cso, $driver, $zone, 50000, 'CashCSO');    // belum disetor
        $this->order($cso, $driver, $zone, 30000, 'CashDriver'); // utang supir

        $r = $this->laporan(['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]);

        $this->assertSame(
            (float) Transaction::cashCsoOutstanding()->sum('amount'),
            (float) $r['cash_position']['cso_unsettled']
        );
        $this->assertSame(
            (float) Transaction::cashDriverOutstanding()->sum('amount'),
            (float) $r['cash_position']['driver_outstanding']
        );
        $this->assertSame(80000.0, (float) $r['cash_position']['total_outside']);
    }

    public function test_posisi_kas_tidak_ikut_disaring_rentang_tanggal(): void
    {
        $cso    = $this->user('cso', '1');
        $driver = $this->user('driver', '1');
        $zone   = Zone::create(['name' => 'Zona A', 'price' => 50000]);

        // Tunai lama yang belum disetor — di luar rentang laporan.
        $this->order($cso, $driver, $zone, 50000, 'CashCSO', now()->subDays(30)->toDateString());

        $r = $this->laporan([
            'date_from' => now()->toDateString(),
            'date_to'   => now()->toDateString(),
        ]);

        $this->assertSame(0.0, (float) $r['summary']['gross'], 'Pendapatan harus mengikuti rentang.');
        $this->assertSame(50000.0, (float) $r['cash_position']['cso_unsettled'],
            'Posisi kas adalah saldo saat ini, bukan peristiwa dalam periode.');
    }

    // === Kompatibilitas & akses ============================================

    public function test_mode_month_lama_tetap_bekerja(): void
    {
        $cso    = $this->user('cso', '1');
        $driver = $this->user('driver', '1');
        $zone   = Zone::create(['name' => 'Zona A', 'price' => 50000]);

        $this->order($cso, $driver, $zone, 50000, 'CashCSO', now()->startOfMonth()->toDateString());

        $r = $this->laporan(['month' => now()->format('Y-m')]);

        // Kunci lama harus tetap ada agar klien lama tidak rusak.
        $this->assertSame(50000, $r['total']);
        $this->assertSame(50000, $r['cash_cso']);
        $this->assertSame(10000, $r['fee']);
        $this->assertSame(self::RATE, (float) $r['fee_rate']);
    }

    public function test_endpoint_hanya_untuk_admin(): void
    {
        Sanctum::actingAs($this->user('cso', '9'));

        $this->getJson('/api/admin/reports/revenue?date_from=' . now()->toDateString())
            ->assertStatus(403);
    }
}
