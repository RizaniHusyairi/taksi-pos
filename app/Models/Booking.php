<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Models\Zone;


class Booking extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'cso_id',
        'driver_id',
        'zone_id',
        'price',
        'status',
        'manual_destination',
        'passenger_phone'
    ];

    /**
     * Relasi ke User (CSO)
     */
    public function cso(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cso_id');
    }

    /**
     * Relasi ke User (Driver)
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /**
     * Relasi ke Zona Tujuan
     */
    public function zoneTo(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }
    
    // --- TAMBAHKAN INI ---
    public function transaction()
    {
        return $this->hasOne(Transaction::class, 'booking_id', 'id');
    }
}
