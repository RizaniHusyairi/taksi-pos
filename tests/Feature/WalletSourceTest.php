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
 * Asal transaksi di balik angka pada kartu Rincian Saldo dompet supir.
 *
 * Yang dijaga di sini bukan "endpoint mengembalikan data", melainkan satu hal
 * yang jauh lebih penting: **penjumlahan daftar harus sama persis dengan angka
 * yang dilihat supir di kartu**. Begitu keduanya berselisih, layar yang dibuat
 * untuk membangun kepercayaan justru jadi bukti bahwa angkanya tidak bisa
 * dipercaya — lebih buruk daripada tidak menampilkan rincian sama sekali.
 */
class WalletSourceTest extends TestCase
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

    /** $zone null = order manual (tanpa zona). */
    private function trx(
        User $driver, ?Zone $zone, string $method, float $amount,
        string $payout = 'Unpaid', string $status = 'Completed'
    ): Transaction {
        $booking = Booking::create([
            'cso_id'    => $this->cso()->id,
            'driver_id' => $driver->id,
            'zone_id'   => $zone?->id,
            'price'     => $amount,
            'status'    => $status,
            'manual_destination' => $zone ? null : 'Tujuan manual',
        ]);

        return Transaction::create([
            'booking_id'    => $booking->id,
            'method'        => $method,
            'amount'        => $amount,
            'payout_status' => $payout,
        ]);
    }

    private function cso(): User
    {
        return User::firstOrCreate(
            ['username' => 'cso1'],
            [
                'name' => 'CSO', 'email' => 'cso1@x.test',
                'password' => bcrypt('x'), 'role' => 'cso', 'active' => true,
            ]
        );
    }

    private function daftar(User $driver, string $bucket): array
    {
        Sanctum::actingAs($driver);

        return $this->getJson('/api/driver/balance/transactions?bucket=' . $bucket)
            ->assertOk()
            ->json();
    }

    private function kartu(User $driver): array
    {
        Sanctum::actingAs($driver);

        return $this->getJson('/api/driver/balance')->assertOk()->json('breakdown');
    }

    // === Kecocokan daftar dengan kartu =====================================

    public function test_penjumlahan_daftar_sama_dengan_angka_di_kartu(): void
    {
        $driver = $this->user('driver', '1');
        $zone   = Zone::create(['name' => 'Zona A', 'price' => 100000]);

        // Pemasukan: QRIS + tunai kasir.
        $this->trx($driver, $zone, 'QRIS', 100000);
        $this->trx($driver, $zone, 'CashCSO', 50000);
        // Utang berzona (persentase) dan manual (flat).
        $this->trx($driver, $zone, 'CashDriver', 200000);
        $this->trx($driver, null, 'CashDriver', 75000);

        $kartu = $this->kartu($driver);

        $pasangan = [
            'income'        => $kartu['income']['commission'],
            'debt_standard' => $kartu['debt']['standard_fee'],
            'debt_manual'   => $kartu['debt']['manual_fee'],
        ];

        foreach ($pasangan as $bucket => $angkaKartu) {
            $r = $this->daftar($driver, $bucket);

            $this->assertSame(
                (float) $angkaKartu,
                (float) collect($r['items'])->sum('contribution'),
                "Penjumlahan baris '{$bucket}' harus sama dengan angka di kartu."
            );
            $this->assertSame((float) $angkaKartu, (float) $r['total']);
        }
    }

    public function test_order_manual_dipotong_flat_bukan_persentase(): void
    {
        $driver = $this->user('driver', '1');

        // Rp 75.000 → persentase 20% = 15.000, tapi yang benar tarif flat 10.000.
        $this->trx($driver, null, 'CashDriver', 75000);

        $r = $this->daftar($driver, 'debt_manual');

        $this->assertCount(1, $r['items']);
        $this->assertSame((float) self::FLAT, (float) $r['items'][0]['contribution']);
        $this->assertNotSame(15000.0, (float) $r['items'][0]['contribution']);
    }

    /**
     * Sisi pemasukan memang memakai persentase rata, termasuk untuk order
     * manual. Kalau suatu saat diganti memakai CommissionCalculator, angkanya
     * tidak akan cocok lagi dengan "Komisi koperasi" di kartu — test ini yang
     * akan menangkapnya.
     */
    public function test_pemasukan_order_manual_tetap_persentase(): void
    {
        $driver = $this->user('driver', '1');
        $this->trx($driver, null, 'CashCSO', 75000);

        $r = $this->daftar($driver, 'income');

        $this->assertSame(15000.0, (float) $r['items'][0]['contribution']);
        $this->assertSame(
            (float) $this->kartu($driver)['income']['commission'],
            (float) $r['total']
        );
    }

    // === Yang TIDAK boleh ikut =============================================

    public function test_hanya_transaksi_milik_supir_yang_bersangkutan(): void
    {
        $driver = $this->user('driver', '1');
        $lain   = $this->user('driver', '2');
        $zone   = Zone::create(['name' => 'Zona A', 'price' => 100000]);

        $this->trx($driver, $zone, 'CashDriver', 100000);
        $this->trx($lain, $zone, 'CashDriver', 999000);

        $r = $this->daftar($driver, 'debt_standard');

        $this->assertCount(1, $r['items']);
        $this->assertSame(100000.0, (float) $r['items'][0]['amount']);
    }

    public function test_yang_sudah_lunas_dan_belum_selesai_tidak_ikut(): void
    {
        $driver = $this->user('driver', '1');
        $zone   = Zone::create(['name' => 'Zona A', 'price' => 100000]);

        $this->trx($driver, $zone, 'CashDriver', 100000);                       // ikut
        $this->trx($driver, $zone, 'CashDriver', 200000, 'Paid');               // sudah lunas
        $this->trx($driver, $zone, 'CashDriver', 300000, 'Unpaid', 'Assigned'); // belum selesai

        $r = $this->daftar($driver, 'debt_standard');

        $this->assertCount(1, $r['items']);
        $this->assertSame(100000.0, (float) $r['items'][0]['amount']);
    }

    // === Bentuk balasan & akses ============================================

    public function test_baris_memuat_tujuan_dan_metode(): void
    {
        $driver = $this->user('driver', '1');
        $zone   = Zone::create(['name' => 'Bandara Kota', 'price' => 100000]);
        $this->trx($driver, $zone, 'QRIS', 100000);

        $item = $this->daftar($driver, 'income')['items'][0];

        $this->assertSame('Bandara Kota', $item['destination']);
        $this->assertSame('QRIS', $item['method']);
        $this->assertArrayHasKey('date', $item);
        $this->assertArrayHasKey('booking_id', $item);
    }

    public function test_order_tanpa_zona_memakai_tujuan_manualnya(): void
    {
        $driver = $this->user('driver', '1');
        $this->trx($driver, null, 'CashDriver', 50000);

        $this->assertSame('Tujuan manual', $this->daftar($driver, 'debt_manual')['items'][0]['destination']);
    }

    public function test_bucket_tidak_dikenal_ditolak(): void
    {
        $driver = $this->user('driver', '1');
        Sanctum::actingAs($driver);

        $this->getJson('/api/driver/balance/transactions?bucket=ngawur')->assertStatus(422);
        $this->getJson('/api/driver/balance/transactions')->assertStatus(422);
    }

    public function test_endpoint_hanya_untuk_supir(): void
    {
        Sanctum::actingAs($this->cso());

        $this->getJson('/api/driver/balance/transactions?bucket=income')->assertStatus(403);
    }
}
