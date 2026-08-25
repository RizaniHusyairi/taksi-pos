<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Booking;
use App\Models\CsoDeposit;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ikon lonceng panel admin.
 *
 * Sebelumnya lonceng itu tombol mati dengan titik merah yang di-hardcode —
 * selalu menyala tanpa peduli ada tidaknya sesuatu. Test di sini menjaga tiga
 * hal yang membuatnya tetap layak dipercaya:
 *
 *   1. status "sudah dibaca" berlaku PER ADMIN, bukan dibagi bersama;
 *   2. antrean yang belum diproses tetap terlihat walau notifikasinya sudah
 *      terbaca — supaya lonceng tidak tampak bersih saat pekerjaan menumpuk;
 *   3. gagal menulis notifikasi tidak pernah membatalkan aksi utama.
 */
class AdminNotificationTest extends TestCase
{
    use RefreshDatabase;

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

    private function lonceng(User $admin): array
    {
        Sanctum::actingAs($admin);

        return $this->getJson('/api/admin/notifications')->assertOk()->json();
    }

    /** Satu order tunai CSO yang siap disetor. */
    private function tunaiCso(User $cso, User $driver, Zone $zone, float $amount): Transaction
    {
        $booking = Booking::create([
            'cso_id' => $cso->id, 'driver_id' => $driver->id, 'zone_id' => $zone->id,
            'price' => $amount, 'status' => 'Completed',
        ]);

        return Transaction::create([
            'booking_id' => $booking->id, 'method' => 'CashCSO',
            'amount' => $amount, 'payout_status' => 'Unpaid',
        ]);
    }

    // === Peristiwa tercatat ================================================

    public function test_setoran_cso_memunculkan_notifikasi(): void
    {
        $cso    = $this->user('cso', '1');
        $driver = $this->user('driver', '1');
        $zone   = Zone::create(['name' => 'Zona A', 'price' => 50000]);
        $this->tunaiCso($cso, $driver, $zone, 50000);

        Sanctum::actingAs($cso);
        $this->postJson('/api/cso/deposits', ['dates' => [now()->toDateString()]])
            ->assertCreated();

        $notif = AdminNotification::where('type', AdminNotification::TYPE_CSO_DEPOSIT)->first();

        $this->assertNotNull($notif, 'Setoran CSO harus tercatat di lonceng admin.');
        $this->assertSame('#cso-deposits', $notif->link);
        $this->assertStringContainsString($cso->name, $notif->body);
    }

    // === Status baca per admin =============================================

    public function test_status_baca_berlaku_per_admin(): void
    {
        AdminNotification::create([
            'type' => AdminNotification::TYPE_CSO_DEPOSIT,
            'title' => 'Contoh', 'link' => '#cso-deposits', 'level' => 'info',
        ]);

        $admin1 = $this->user('admin', '1');
        $admin2 = $this->user('admin', '2');

        $this->assertSame(1, $this->lonceng($admin1)['unread_count']);
        $this->assertSame(1, $this->lonceng($admin2)['unread_count']);

        // Admin pertama membuka loncengnya.
        Sanctum::actingAs($admin1);
        $this->postJson('/api/admin/notifications/read')->assertOk()->assertJson(['unread_count' => 0]);

        $this->assertSame(0, $this->lonceng($admin1)['unread_count']);
        // Inilah bug yang dihindari: kalau status baca menempel pada baris
        // notifikasi, admin kedua tidak akan pernah melihat tandanya.
        $this->assertSame(1, $this->lonceng($admin2)['unread_count'],
            'Admin lain harus tetap melihat notifikasi yang belum ia buka.');
    }

    public function test_notifikasi_baru_setelah_dibaca_terhitung_lagi(): void
    {
        $admin = $this->user('admin', '1');

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/notifications/read')->assertOk();

        $this->travel(2)->seconds();
        AdminNotification::create([
            'type' => AdminNotification::TYPE_WITHDRAWAL,
            'title' => 'Baru', 'link' => '#withdrawals', 'level' => 'warning',
        ]);

        $this->assertSame(1, $this->lonceng($admin)['unread_count']);
    }

    // === Antrean tetap terlihat ============================================

    public function test_antrean_tetap_dihitung_walau_notifikasi_sudah_terbaca(): void
    {
        $cso = $this->user('cso', '1');
        CsoDeposit::create([
            'cso_id' => $cso->id, 'amount' => 100000, 'status' => 'Pending',
            'period_dates' => [now()->toDateString()], 'transactions_count' => 1,
            'submitted_at' => now(),
        ]);

        $admin = $this->user('admin', '1');
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/notifications/read')->assertOk();

        $r = $this->lonceng($admin);

        $this->assertSame(0, $r['unread_count']);
        // Lonceng yang tampak bersih padahal ada 1 setoran menunggu adalah
        // persis kegagalan yang membuat indikator berhenti dipercaya.
        $this->assertSame(1, $r['pending']['cso_deposit']);
    }

    public function test_hitungan_antrean_mencakup_keempat_jenis(): void
    {
        $admin = $this->user('admin', '1');
        $r = $this->lonceng($admin);

        $this->assertSame(
            ['withdrawal', 'cso_deposit', 'driver_deposit', 'method_dispute'],
            array_keys($r['pending'])
        );
    }

    // === Ketahanan =========================================================

    public function test_gagal_menulis_notifikasi_tidak_membatalkan_setoran(): void
    {
        $cso    = $this->user('cso', '1');
        $driver = $this->user('driver', '1');
        $zone   = Zone::create(['name' => 'Zona A', 'price' => 50000]);
        $this->tunaiCso($cso, $driver, $zone, 50000);

        // Tabel notifikasi dijatuhkan: penulisannya pasti gagal.
        DB::statement('DROP TABLE admin_notifications');

        Sanctum::actingAs($cso);
        $this->postJson('/api/cso/deposits', ['dates' => [now()->toDateString()]])
            ->assertCreated();

        // Uang tetap tercatat disetor — inilah yang tidak boleh ikut gagal.
        $this->assertDatabaseCount('cso_deposits', 1);
    }

    // === Akses =============================================================

    public function test_endpoint_hanya_untuk_admin(): void
    {
        Sanctum::actingAs($this->user('cso', '9'));

        $this->getJson('/api/admin/notifications')->assertStatus(403);
        $this->postJson('/api/admin/notifications/read')->assertStatus(403);
    }
}
