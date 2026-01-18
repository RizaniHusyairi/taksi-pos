<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class PaymentPolicy
{
    /**
     * CSO boleh mencatat pembayaran
     * - booking harus Completed
     * - belum pernah dibayar
     */
    public function record(User $user, Booking $booking): bool
    {
        return $user->role === 'cso'
            && $booking->status === 'Completed'
            && ! $booking->transactions()->exists();
    }
}
