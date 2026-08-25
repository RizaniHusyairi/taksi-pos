<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DriverProfile;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sengketa metode pembayaran: supir menyanggah menerima uang tunai.
 *
 * Mengabulkan sanggahan MEMINDAHKAN kewajiban uang dari supir ke CSO, jadi yang
 * diuji di sini bukan cuma label yang berubah, tapi kedua sisi buku besarnya:
 * utang supir hilang DAN tagihan setoran CSO muncul. Kalau hanya satu sisi yang
 * berpindah, uangnya lenyap dari sistem — kesalahan yang jauh lebih buruk
 * daripada salah label yang ingin diperbaiki fitur ini.
 */
class PaymentMethodDisputeTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;
    private User $cso;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['key' => 'commission_rate'], ['value' => 0.2]);
        Setting::forgetMemo();

        $this->cso = User::create([
            'name' => 'Kasir', 'username' => 'kasir', 'email' => 'k@x.test',
            'password' => bcrypt('x'), 'role' => 'cso', 'active' => true,
        ]);

        $this->driver = User::create([
            'name' => 'Supir', 'username' => 'supir', 'email' => 's@x.test',
            'password' => bcrypt('x'), 'role' => 'driver', 'active' => true,
        ]);
        DriverProfile::create([
            'user_id' => $this->driver->id, 'car_model' => 'Avanza',
            'plate_number' => 'KT 1234 AB', 'line_number' => 1,
        ]);
    }

    private function admin(): User
    {
        return User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Admin', 'email' => 'a@x.test',
                'password' => bcrypt('x'), 'role' => 'admin', 'active' => true,
            ]
        );
    }

    /** Order Rp 100.000 tunai-ke-supir yang sudah selesai → utang komisi 20.000. */
    private function trx(array $extra = []): Transaction
    {
        $booking = Booking::create([
            'cso_id'    => $this->cso->id,
            'driver_id' => $this->driver->id,
            'zone_id'   => Zone::firstOrCreate(['name' => 'Kota'], ['price' => 100000])->id,
            'price'     => 100000,
            'status'    => 'Completed',
        ]);

        return Transaction::create(array_merge([
            'booking_id'     => $booking->id,
            'method'         => 'CashDriver',
            'amount'         => 100000,
            'payout_status'  => 'Unpaid',
            'deposit_status' => 'Unsettled',
        ], $extra));
    }

    private function sanggah(Transaction $t, string $note = 'Uang diterima kasir, bukan saya.')
    {
        Sanctum::actingAs($this->driver);
        return $this->postJson("/api/driver/transactions/{$t->id}/dispute-method", ['note' => $note]);
    }

    // === Supir mengajukan sanggahan =======================================

    public function test_supir_bisa_menyanggah_transaksi_tunai_miliknya(): void
    {
        $t = $this->trx();

        $this->sanggah($t)->assertStatus(201);

        $t->refresh();
        $this->assertSame('Open', $t->method_dispute_status);
        $this->assertSame('Uang diterima kasir, bukan saya.', $t->method_dispute_note);
        $this->assertNotNull($t->method_disputed_at);
        // Metodenya BELUM berubah — hanya admin yang boleh memindahkan uang.
        $this->assertSame('CashDriver', $t->method);
    }

    public function test_alasan_wajib_diisi(): void
    {
        $t = $this->trx();
        Sanctum::actingAs($this->driver);

        $this->postJson("/api/driver/transactions/{$t->id}/dispute-method", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
    }

    public function test_supir_tidak_bisa_menyanggah_transaksi_supir_lain(): void
    {
        $t = $this->trx();

        $lain = User::create([
            'name' => 'Lain', 'username' => 'lain', 'email' => 'l@x.test',
            'password' => bcrypt('x'), 'role' => 'driver', 'active' => true,
        ]);
        DriverProfile::create([
            'user_id' => $lain->id, 'car_model' => 'Xenia', 'plate_number' => 'KT 9 ZZ',
        ]);

        Sanctum::actingAs($lain);
        $this->postJson("/api/driver/transactions/{$t->id}/dispute-method", ['note' => 'bukan saya'])
            ->assertStatus(403);
    }

    /** QRIS & CashCSO tidak menagih apa pun ke supir — tidak ada yang disanggah. */
    public function test_hanya_metode_tunai_ke_supir_yang_bisa_disanggah(): void
    {
        foreach (['QRIS', 'CashCSO'] as $metode) {
            $t = $this->trx(['method' => $metode]);
            $this->sanggah($t)->assertStatus(422);
            $this->assertNull($t->fresh()->method_dispute_status);
        }
    }

    /**
     * Transaksi yang sudah masuk pencairan/setoran punya buku yang sudah
     * tutup. Mengoreksinya lewat aplikasi akan mengubah angka yang sudah
     * dibayarkan.
     */
    public function test_transaksi_yang_sudah_diselesaikan_tidak_bisa_disanggah(): void
    {
        foreach (['Processing', 'Paid'] as $status) {
            $t = $this->trx(['payout_status' => $status]);
            $this->sanggah($t)->assertStatus(422);
        }
    }

    public function test_satu_transaksi_hanya_bisa_disanggah_sekali(): void
    {
        $t = $this->trx();

        $this->sanggah($t)->assertStatus(201);
        $this->sanggah($t)->assertStatus(422);
    }

    /** Aplikasi tidak boleh menebak aturannya sendiri — server yang memberi flag. */
    public function test_riwayat_trip_menandai_transaksi_yang_bisa_disanggah(): void
    {
        $bisa  = $this->trx();
        $tidak = $this->trx(['method' => 'QRIS']);

        Sanctum::actingAs($this->driver);
        $riwayat = collect($this->getJson('/api/driver/history')->assertOk()->json())
            ->keyBy('id');

        $this->assertTrue($riwayat[$bisa->id]['can_dispute']);
        $this->assertFalse($riwayat[$tidak->id]['can_dispute']);
    }

    // === Admin memutus ====================================================

    /**
     * Inti fiturnya: kewajiban benar-benar BERPINDAH, bukan cuma labelnya.
     */
    public function test_dikabulkan_memindahkan_kewajiban_dari_supir_ke_cso(): void
    {
        $t = $this->trx();
        $this->sanggah($t);

        // Sebelum: supir berutang 20.000, CSO tidak punya tagihan.
        Sanctum::actingAs($this->driver);
        $this->assertSame(20000, (int) $this->getJson('/api/driver/balance')->json('debt_pending'));
        Sanctum::actingAs($this->cso);
        $this->assertSame(0.0, (float) $this->getJson('/api/cso/deposits/outstanding')->json('grand_total'));

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/method-disputes/{$t->id}/uphold", ['admin_note' => 'Terbukti.'])
            ->assertOk();

        $t->refresh();
        $this->assertSame('CashCSO', $t->method);
        $this->assertSame('CashDriver', $t->original_method);
        $this->assertSame('Upheld', $t->method_dispute_status);

        // Sesudah: utang supir lepas & berubah jadi hak pemasukan...
        Sanctum::actingAs($this->driver);
        $dompet = $this->getJson('/api/driver/balance')->json();
        $this->assertSame(0, (int) $dompet['debt_pending']);
        $this->assertSame(80000, (int) $dompet['income_pending']); // 100k - 20% komisi

        // ...dan tagihan setoran muncul di CSO.
        Sanctum::actingAs($this->cso);
        $this->assertSame(100000.0, (float) $this->getJson('/api/cso/deposits/outstanding')->json('grand_total'));
    }

    /**
     * Transaksi LAMA sempat di-backfill jadi deposit_status 'Settled'
     * (migrasi 2026_08_24_000002). Tanpa memaksa 'Unsettled' saat koreksi,
     * uangnya lenyap: tidak ditagih ke supir, tidak juga ke CSO.
     */
    public function test_transaksi_lama_bertanda_settled_tetap_muncul_sebagai_tagihan_cso(): void
    {
        $t = $this->trx(['deposit_status' => 'Settled']);
        $this->sanggah($t);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/method-disputes/{$t->id}/uphold")->assertOk();

        $this->assertSame('Unsettled', $t->fresh()->deposit_status);

        Sanctum::actingAs($this->cso);
        $this->assertSame(100000.0, (float) $this->getJson('/api/cso/deposits/outstanding')->json('grand_total'));
    }

    public function test_ditolak_mempertahankan_utang_supir(): void
    {
        $t = $this->trx();
        $this->sanggah($t);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/method-disputes/{$t->id}/reject", ['admin_note' => 'Ada tanda terima.'])
            ->assertOk();

        $t->refresh();
        $this->assertSame('Rejected', $t->method_dispute_status);
        $this->assertSame('CashDriver', $t->method);

        Sanctum::actingAs($this->driver);
        $this->assertSame(20000, (int) $this->getJson('/api/driver/balance')->json('debt_pending'));
    }

    public function test_alasan_wajib_saat_menolak(): void
    {
        $t = $this->trx();
        $this->sanggah($t);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/method-disputes/{$t->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_note');
    }

    /** Pelajaran yang sama dengan adminRejectWithdrawal & adminRejectCsoDeposit. */
    public function test_sanggahan_yang_sudah_diputus_tidak_bisa_diputus_lagi(): void
    {
        $t = $this->trx();
        $this->sanggah($t);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/method-disputes/{$t->id}/uphold")->assertOk();

        $this->postJson("/api/admin/method-disputes/{$t->id}/reject", ['admin_note' => 'ganti pikiran'])
            ->assertStatus(422);
        $this->postJson("/api/admin/method-disputes/{$t->id}/uphold")->assertStatus(422);

        // Tidak berubah dua kali: original_method tetap metode aslinya.
        $this->assertSame('CashDriver', $t->fresh()->original_method);
    }

    /**
     * Antara sanggahan diajukan dan diputus, supir bisa saja mengajukan
     * pencairan. Mengoreksi metode saat itu akan mengubah angka yang sudah
     * dikunci pencairan.
     */
    public function test_tidak_bisa_dikabulkan_bila_sudah_masuk_pencairan(): void
    {
        $t = $this->trx();
        $this->sanggah($t);

        // Simulasikan transaksi terkunci pencairan setelah sanggahan dibuat.
        $t->update(['payout_status' => 'Processing']);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/method-disputes/{$t->id}/uphold")
            ->assertStatus(422);

        $this->assertSame('CashDriver', $t->fresh()->method);
    }

    public function test_admin_melihat_daftar_sanggahan_terbuka(): void
    {
        $t = $this->trx();
        $this->sanggah($t);

        Sanctum::actingAs($this->admin());
        $daftar = $this->getJson('/api/admin/method-disputes')->assertOk()->json('data');

        $this->assertCount(1, $daftar);
        $this->assertSame($t->id, $daftar[0]['id']);
        $this->assertSame('Supir', $daftar[0]['booking']['driver']['name']);
        $this->assertSame('Kasir', $daftar[0]['booking']['cso']['name']);
    }

    public function test_endpoint_admin_tertutup_untuk_supir(): void
    {
        Sanctum::actingAs($this->driver);
        $this->getJson('/api/admin/method-disputes')->assertStatus(403);
    }
}
