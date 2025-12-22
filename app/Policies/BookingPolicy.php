<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{

    /**
     * CSO mengonfirmasi pembayaran QRIS / CashCSO
     */
    public function confirmPaymentByCso(User $user, Booking $booking, string $method): bool
    {
        return
            $user->role === 'cso'
            && in_array($method, ['QRIS', 'CashCSO'])
            && $booking->status === 'Assigned'
            && ! $booking->transactions()->exists();
    }

    /**
     * Driver menyelesaikan trip + konfirmasi CashDriver
     */
    public function confirmCashDriver(User $user, Booking $booking): bool
    {
        return
            $user->role === 'driver'
            && $booking->driver_id === $user->id
            && $booking->status === 'CashDriver'
            && ! $booking->transactions()->exists();
    }

    /**
     * CSO boleh membuat booking
     */
    public function create(User $user): bool
    {
        return $user->role === 'cso';
    }

    /**
     * Admin boleh melihat semua booking
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin';
    }

    /**
     * CSO hanya boleh melihat booking miliknya
     * Admin boleh melihat semua
     */
    public function view(User $user, Booking $booking): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'cso') {
            return $booking->cso_id === $user->id;
        }

        if ($user->role === 'driver') {
            return $booking->driver_id === $user->id;
        }

        return false;
    }
}
