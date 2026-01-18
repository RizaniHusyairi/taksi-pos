<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Transaction;
use DomainException;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    /**
     * Proses pembayaran booking (ANTI DOUBLE PAYMENT)
     */
    public function pay(int $bookingId, string $method): Transaction
    {
        return DB::transaction(function () use ($bookingId, $method) {

            // 🔒 Lock booking row
            $booking = Booking::where('id', $bookingId)
                ->lockForUpdate()
                ->firstOrFail();

            // 🚫 Sudah dibayar?
            if ($booking->transaction()->exists()) {
                return $booking->transaction;
            }

            // 🚫 Status tidak valid?
            if ($booking->status !== 'Assigned') {
                throw new DomainException(
                    "Booking cannot be paid in status: {$booking->status}"
                );
            }

            // ✅ Update status booking
            $booking->update(['status' => 'Paid']);

            // ✅ Buat transaksi
            return Transaction::create([
                'booking_id' => $booking->id,
                'method'     => $method,
                'amount'     => $booking->price,
            ]);
        });
    }
}
