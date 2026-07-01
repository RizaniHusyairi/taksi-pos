<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mengunci jaminan keamanan uang pada alur pencairan dana — terutama guard
 * anti DOUBLE-PAYOUT yang diperbaiki sesi ini.
 */
class WithdrawalSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'active' => true,
        ]);
    }

    private function driver(): User
    {
        return User::create([
            'name' => 'Driver', 'username' => 'driver', 'email' => 'd@x.test',
            'password' => bcrypt('x'), 'role' => 'driver', 'active' => true,
        ]);
    }

    /** @return array{0: Withdrawals, 1: Transaction} */
    private function pendingWithdrawal(User $driver): array
    {
        $booking = Booking::create([
            'cso_id' => $driver->id, 'driver_id' => $driver->id,
            'zone_id' => null, 'price' => 100000, 'status' => 'Completed',
        ]);
        $w = Withdrawals::create([
            'driver_id' => $driver->id, 'amount' => 80000,
            'status' => 'Pending', 'requested_at' => now(),
        ]);
        $t = Transaction::create([
            'booking_id' => $booking->id, 'method' => 'QRIS', 'amount' => 100000,
            'payout_status' => 'Processing', 'withdrawal_id' => $w->id,
        ]);

        return [$w, $t];
    }

    public function test_approve_pending_marks_transactions_paid(): void
    {
        Storage::fake('public');
        $driver = $this->driver();
        [$w, $t] = $this->pendingWithdrawal($driver);

        Sanctum::actingAs($this->admin());
        $this->post("/api/admin/withdrawals/{$w->id}/approve", [
            'proof_image' => UploadedFile::fake()->image('proof.jpg', 8, 8),
        ])->assertOk();

        $this->assertSame('Approved', $w->fresh()->status);
        $this->assertSame('Paid', $t->fresh()->payout_status);
    }

    public function test_cannot_reject_after_approve_double_payout_guard(): void
    {
        Storage::fake('public');
        $driver = $this->driver();
        [$w, $t] = $this->pendingWithdrawal($driver);

        Sanctum::actingAs($this->admin());
        $this->post("/api/admin/withdrawals/{$w->id}/approve", [
            'proof_image' => UploadedFile::fake()->image('p.jpg', 8, 8),
        ])->assertOk();

        // Menolak yang sudah Approved harus DITOLAK (422) — kalau lolos, transaksi
        // Paid akan balik jadi Unpaid dan uang yang sudah ditransfer "muncul lagi".
        $this->post("/api/admin/withdrawals/{$w->id}/reject")->assertStatus(422);

        $this->assertSame('Paid', $t->fresh()->payout_status);   // tetap Paid
        $this->assertSame('Approved', $w->fresh()->status);
    }

    public function test_reject_pending_returns_transactions_to_unpaid(): void
    {
        $driver = $this->driver();
        [$w, $t] = $this->pendingWithdrawal($driver);

        Sanctum::actingAs($this->admin());
        $this->post("/api/admin/withdrawals/{$w->id}/reject")->assertOk();

        $this->assertSame('Rejected', $w->fresh()->status);
        $this->assertSame('Unpaid', $t->fresh()->payout_status);
        $this->assertNull($t->fresh()->withdrawal_id);
    }

    public function test_cannot_approve_already_rejected(): void
    {
        Storage::fake('public');
        $driver = $this->driver();
        [$w] = $this->pendingWithdrawal($driver);

        Sanctum::actingAs($this->admin());
        $this->post("/api/admin/withdrawals/{$w->id}/reject")->assertOk();
        $this->post("/api/admin/withdrawals/{$w->id}/approve", [
            'proof_image' => UploadedFile::fake()->image('p.jpg', 8, 8),
        ])->assertStatus(422);
    }
}
