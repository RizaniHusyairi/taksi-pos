<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverProfile extends Model
{
    protected $fillable = [
        'user_id', 
        'car_model', 
        'plate_number', 
        'bank_name', 
        'account_number', 
        'status',
        'line_number',
        'last_queue_date',
        'out_of_area_since',
        'auto_join_blocked',
        'latitude',
        'longitude',
        'location_updated_at',
        'last_in_area',
        'airport_entries',
        'airport_exits',
        'track_lat',
        'track_lng',
        // Jejak dugaan fake GPS — lihat migrasi
        // 2026_08_24_000003_add_spoof_detection_to_driver_profiles.
        'spoof_strikes',
        'last_spoof_at',
        'last_spoof_reason',
    ];

    protected $casts = [
        'location_updated_at' => 'datetime',
        'last_in_area' => 'boolean',
        'last_spoof_at' => 'datetime',
    ];

    public function user()
    {
        // Profil driver ini dimiliki oleh satu user
        return $this->belongsTo(User::class);
    }
}
