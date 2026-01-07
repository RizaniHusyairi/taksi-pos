<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Booking;
use App\Models\User;


class Transaction extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'booking_id',
        'method',
        'payment_proof',
        'withdrawal_id',
        'payout_status',
        'amount',
    ];

    /**
     * Relasi bahwa setiap transaksi dimiliki oleh satu booking.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Relasi bahwa setiap transaksi dimiliki oleh satu supir melalui booking.
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /**
     * Relasi bahwa setiap transaksi dibuat oleh satu CSO melalui booking.
     */
    public function cso(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cso_id');
    }
}
