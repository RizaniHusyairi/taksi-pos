<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mengunci lima celah kecurangan yang ditutup pada audit ini. Semua test di
 * sini menguji SESUATU YANG HILANG (route yang dihapus, link yang tidak lagi
 * dikirim) — justru jenis perbaikan yang paling gampang tanpa sengaja
 * dikembalikan orang lain, karena tidak ada kode yang menunjukkan keberadaannya.
 */
class AntiFraudGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Rem laju memakai cache 'array' di testing; bersihkan agar hitungan
        // satu test tidak bocor ke test berikutnya.
        RateLimiter::clear('api-ip:127.0.0.1');
    }

    private function user(string $role, bool $active = true, string $suffix = ''): User
    {
        return User::create([
            'name'     => ucfirst($role),
            'username' => $role . $suffix,
            'email'    => $role . $suffix . '@x.test',
            'password' => bcrypt('rahasia123'),
            'role'     => $role,
            'active'   => $active,
        ]);
    }

    // === 1. Route reset antrian publik sudah dihapus =====================

    /**
     * Dulu keduanya GET publik di luar middleware auth: siapa pun bisa
     * menghanguskan seluruh antrian & memaksa rotasi mulai dari L1.
     */
    public function test_route_reset_antrian_publik_tidak_ada_lagi(): void
    {
        $this->get('/reset-daily-queue')->assertNotFound();
        $this->get('/init-rotation')->assertNotFound();
    }

    // === 2. Akun nonaktif tidak bisa login lewat web =====================

    public function test_cso_nonaktif_ditolak_login_web(): void
    {
        $this->user('cso', active: false);

        $this->post('/login', ['username' => 'cso', 'password' => 'rahasia123'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_admin_aktif_tetap_bisa_login_web(): void
    {
        $admin = $this->user('admin');

        $this->post('/login', ['username' => 'admin', 'password' => 'rahasia123'])
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin);
    }

    /**
     * Halaman CSO web sudah dihapus — CSO hanya lewat aplikasi mobile. Sesinya
     * harus ikut ditutup, bukan sekadar tidak punya halaman tujuan.
     */
    public function test_cso_aktif_ditolak_login_web(): void
    {
        $this->user('cso');

        $this->post('/login', ['username' => 'cso', 'password' => 'rahasia123'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_rute_cso_dan_driver_web_sudah_tidak_ada(): void
    {
        $this->get('/cso')->assertNotFound();
        $this->get('/driver')->assertNotFound();
    }

    public function test_driver_aktif_ditolak_login_web(): void
    {
        $this->user('driver');

        $this->post('/login', ['username' => 'driver', 'password' => 'rahasia123'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    // === 3. Rem laju ======================================================

    public function test_login_api_direm_setelah_lima_percobaan(): void
    {
        $this->user('cso');

        // 5 percobaan gagal masih dilayani (401), yang ke-6 ditolak rem laju.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['username' => 'cso', 'password' => 'salah'])
                ->assertStatus(401);
        }

        $this->postJson('/api/login', ['username' => 'cso', 'password' => 'salah'])
            ->assertStatus(429);
    }

    public function test_grup_api_punya_rem_laju(): void
    {
        $this->assertContains('throttle:api', app('router')->getMiddlewareGroups()['api']);
        $this->assertNotNull(
            app(\Illuminate\Cache\RateLimiter::class)->limiter('api'),
            'Limiter bernama "api" harus terdaftar (AppServiceProvider::configureRateLimiting).'
        );
    }

    /**
     * Kuota dihitung per-PENGGUNA, bukan per-IP. Kalau ini pernah berubah jadi
     * per-IP lagi, puluhan supir di balik satu WiFi bandara akan saling
     * mengunci — gangguan layanan, bukan keamanan.
     */
    /**
     * Kunci ember yang dipakai ThrottleRequests untuk limiter bernama:
     * md5(namaLimiter . kunciLimit) — lihat vendor ThrottleRequests::
     * handleRequestUsingNamedLimiter(). Dihitung, bukan ditebak, supaya test
     * ini gagal dengan jujur kalau Laravel mengubah skemanya.
     */
    private function kunciEmber(string $kunciLimit): string
    {
        return md5('api' . $kunciLimit);
    }

    public function test_kuota_api_dikunci_per_pengguna_bukan_per_ip(): void
    {
        $driver = $this->user('driver');
        Sanctum::actingAs($driver);

        // Endpoint terautentikasi yang membalas 200, supaya header rem laju
        // benar-benar sampai ke response (pada 4xx, exception melewati
        // middleware sebelum header sempat ditempel).
        $this->getJson('/api/user')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 120);

        // Yang terpakai adalah ember milik PENGGUNA, bukan ember IP.
        $this->assertSame(
            1,
            RateLimiter::attempts($this->kunciEmber('api-user:' . $driver->id))
        );
        $this->assertSame(
            0,
            RateLimiter::attempts($this->kunciEmber('api-ip:127.0.0.1'))
        );
    }

    /**
     * Bukti bahwa embernya benar-benar TERPISAH: satu supir yang memakai habis
     * jatahnya tidak boleh menjatuhkan supir lain di IP yang sama. Inilah
     * alasan limiter ini bernama dan tidak sekadar `throttle:120,1`.
     */
    public function test_satu_supir_kehabisan_kuota_tidak_mengunci_supir_lain(): void
    {
        $a = $this->user('driver', suffix: 'a');
        $b = $this->user('driver', suffix: 'b');

        // Habiskan jatah supir A.
        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit($this->kunciEmber('api-user:' . $a->id), 60);
        }

        Sanctum::actingAs($a);
        $this->getJson('/api/user')->assertStatus(429);

        Sanctum::actingAs($b);
        $this->getJson('/api/user')->assertOk();
    }

    // === 4. Jalur booking lama yang tanpa jejak uang sudah ditutup ========

    /**
     * `POST /api/cso/bookings` membuat order TANPA baris Transaction, dan
     * `POST /api/cso/payment` menerima booking_id milik CSO mana pun serta bisa
     * menumpuk transaksi kedua. Keduanya harus benar-benar hilang, bukan
     * sekadar tidak dipakai UI.
     */
    public function test_endpoint_booking_dan_payment_lama_sudah_dihapus(): void
    {
        Sanctum::actingAs($this->user('cso'));

        $this->postJson('/api/cso/bookings', [])->assertNotFound();
        $this->postJson('/api/cso/payment', [])->assertNotFound();
    }

    public function test_controller_tidak_lagi_punya_method_lama(): void
    {
        $controller = \App\Http\Controllers\Api\CsoApiController::class;

        $this->assertFalse(method_exists($controller, 'storeBooking'));
        $this->assertFalse(method_exists($controller, 'recordPayment'));
        // Satu-satunya pintu order harus tetap ada.
        $this->assertTrue(method_exists($controller, 'processOrder'));
    }

    // === 5. Token struk tidak lagi bocor ke supir =========================

    /**
     * `receipt_token` adalah satu-satunya kunci form penilaian penumpang yang
     * terbuka tanpa login. Selama token itu ikut dikirim ke supir, supir bisa
     * memberi bintang 5 untuk dirinya sendiri sebelum penumpang sempat.
     */
    public function test_email_order_ke_supir_tidak_memuat_link_struk(): void
    {
        $isi = file_get_contents(resource_path('views/emails/new_order_driver.blade.php'));

        $this->assertStringNotContainsString('$receiptUrl', $isi);
        $this->assertStringNotContainsString('receipt.show', $isi);

        // Mailable-nya pun tidak boleh lagi menerima URL struk.
        $ctor = (new \ReflectionClass(\App\Mail\NewOrderForDriver::class))->getConstructor();
        $this->assertSame(1, $ctor->getNumberOfParameters());
        $this->assertSame('booking', $ctor->getParameters()[0]->getName());
    }

    public function test_pesan_wa_ke_supir_tidak_memuat_link_struk(): void
    {
        $isi = file_get_contents(
            app_path('Http/Controllers/Api/CsoApiController.php')
        );

        // Ambil hanya blok pesan WA untuk supir.
        $mulai = strpos($isi, '$msgDriver = ');
        $this->assertNotFalse($mulai, 'Blok pesan WA supir tidak ditemukan.');
        $blok = substr($isi, $mulai, strpos($isi, ';', $mulai) - $mulai);

        $this->assertStringNotContainsString('receiptUrl', $blok);
        // Penumpang TETAP harus menerima struknya.
        $this->assertStringContainsString('$receiptUrl', $isi);
    }
}
