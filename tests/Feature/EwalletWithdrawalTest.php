<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DriverProfile;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pencairan dompet supir ke e-wallet (ShopeePay/DANA/GoPay/OVO).
 *
 * Dua janji yang dikunci di sini:
 *  1. Tujuan pencairan DISALIN ke baris withdrawals saat pengajuan dibuat.
 *     Kalau tidak, supir yang mengganti tujuan setelah mengajukan akan
 *     mengubah isi bukti transfer & PDF pencairan lamanya.
 *  2. Aplikasi lama yang hanya mengirim `account_number` (tanpa
 *     `payout_method`) tetap tersimpan sebagai tujuan bank.
 */
class EwalletWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private function driver(array $profil = []): User
    {
        $user = User::create([
            'name' => 'Supir', 'username' => 'supir', 'email' => 's@x.test',
            'password' => bcrypt('x'), 'role' => 'driver', 'active' => true,
        ]);

        DriverProfile::create([
            'user_id'      => $user->id,
            'car_model'    => 'Avanza',
            'plate_number' => 'KT 1234 AB',
            'line_number'  => 1,
            'status'       => 'offline',
        ] + $profil);

        return $user;
    }

    /** Satu order QRIS selesai → saldo bersih supir di atas batas minimal. */
    private function saldoOrder(User $driver, int $harga = 100000): Transaction
    {
        Setting::updateOrCreate(['key' => 'commission_rate'], ['value' => 0.2]);
        Setting::forgetMemo();

        $booking = Booking::create([
            'cso_id' => $driver->id, 'driver_id' => $driver->id,
            'zone_id' => null, 'price' => $harga, 'status' => 'Completed',
        ]);

        return Transaction::create([
            'booking_id'    => $booking->id,
            'method'        => 'QRIS',
            'amount'        => $harga,
            'payout_status' => 'Unpaid',
        ]);
    }

    // === Pengajuan =========================================================

    public function test_supir_ewallet_bisa_mengajukan_dan_tujuannya_disalin(): void
    {
        $driver = $this->driver([
            'payout_method'       => 'ewallet',
            'ewallet_provider'    => 'dana',
            'ewallet_number'      => '081234567890',
            'ewallet_holder_name' => 'Budi Santoso',
        ]);
        $this->saldoOrder($driver);

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/withdrawals')->assertStatus(201);

        $w = Withdrawals::where('driver_id', $driver->id)->firstOrFail();
        $this->assertSame('ewallet', $w->payout_method);
        $this->assertSame('dana', $w->payout_provider);
        $this->assertSame('081234567890', $w->payout_account);
        $this->assertSame('Budi Santoso', $w->payout_holder_name);
        $this->assertSame('DANA', $w->payout_channel);
    }

    public function test_ewallet_belum_lengkap_ditolak(): void
    {
        // Provider sudah dipilih tapi nomor & nama belum diisi.
        $driver = $this->driver([
            'payout_method'    => 'ewallet',
            'ewallet_provider' => 'ovo',
            // Rekening bank lama masih ada, tapi TIDAK boleh dipakai diam-diam
            // karena tujuan aktif supir adalah e-wallet.
            'bank_name'        => 'Bank BTN',
            'account_number'   => '1234567890',
        ]);
        $this->saldoOrder($driver);

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/withdrawals')->assertStatus(422);

        $this->assertSame(0, Withdrawals::where('driver_id', $driver->id)->count());
    }

    public function test_snapshot_tidak_ikut_berubah_saat_supir_ganti_tujuan(): void
    {
        $driver = $this->driver([
            'payout_method'       => 'ewallet',
            'ewallet_provider'    => 'gopay',
            'ewallet_number'      => '081200000001',
            'ewallet_holder_name' => 'Budi',
        ]);
        $this->saldoOrder($driver);

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/withdrawals')->assertStatus(201);

        $this->postJson('/api/driver/bank-details', [
            'payout_method'       => 'ewallet',
            'ewallet_provider'    => 'ovo',
            'ewallet_number'      => '081299999999',
            'ewallet_holder_name' => 'Budi Lain',
        ])->assertOk();

        $w = Withdrawals::where('driver_id', $driver->id)->firstOrFail();
        $this->assertSame('gopay', $w->payout_provider);
        $this->assertSame('081200000001', $w->payout_account);
    }

    // === Pengaturan tujuan =================================================

    public function test_provider_di_luar_daftar_ditolak(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/bank-details', [
            'payout_method'       => 'ewallet',
            'ewallet_provider'    => 'linkaja',
            'ewallet_number'      => '081234567890',
            'ewallet_holder_name' => 'Budi',
        ])->assertStatus(422)->assertJsonValidationErrors('ewallet_provider');
    }

    public function test_nomor_ewallet_kurang_dari_sepuluh_digit_ditolak(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/bank-details', [
            'payout_method'       => 'ewallet',
            'ewallet_provider'    => 'dana',
            'ewallet_number'      => '081234',
            'ewallet_holder_name' => 'Budi',
        ])->assertStatus(422)->assertJsonValidationErrors('ewallet_number');
    }

    public function test_payload_aplikasi_lama_tetap_tersimpan_sebagai_bank(): void
    {
        $driver = $this->driver();

        Sanctum::actingAs($driver);
        // Tanpa payout_method sama sekali — persis seperti build lama.
        $this->postJson('/api/driver/bank-details', [
            'account_number' => '1234567890',
        ])->assertOk();

        $profil = $driver->fresh()->driverProfile;
        $this->assertSame('bank', $profil->payout_method);
        $this->assertSame('Bank BTN', $profil->bank_name);
        $this->assertSame('1234567890', $profil->account_number);
    }

    public function test_pindah_ke_ewallet_tidak_menghapus_rekening_bank(): void
    {
        $driver = $this->driver([
            'bank_name'      => 'Bank BTN',
            'account_number' => '1234567890',
        ]);

        Sanctum::actingAs($driver);
        $this->postJson('/api/driver/bank-details', [
            'payout_method'       => 'ewallet',
            'ewallet_provider'    => 'shopeepay',
            'ewallet_number'      => '081234567890',
            'ewallet_holder_name' => 'Budi',
        ])->assertOk();

        $profil = $driver->fresh()->driverProfile;
        $this->assertSame('ewallet', $profil->payout_method);
        $this->assertSame('1234567890', $profil->account_number);
    }
}
