<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu titik jejak pergerakan supir. Kumpulan titik (urut recorded_at)
 * membentuk RUTE yang digambar di peta admin.
 */
class DriverLocationLog extends Model
{
    public $timestamps = false; // hanya pakai recorded_at

    protected $fillable = ['user_id', 'latitude', 'longitude', 'recorded_at'];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];
}
