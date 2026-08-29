<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverProfile extends Model
{
    /**
     * Dompet elektronik yang boleh dipakai sebagai tujuan pencairan.
     * Kunci = nilai tersimpan di kolom ewallet_provider, isi = label tampilan.
     * Dipakai bersama oleh validasi API dan label di panel admin/PDF/WA supaya
     * daftar providernya cuma hidup di satu tempat.
     */
    public const EWALLET_PROVIDERS = [
        'shopeepay' => 'ShopeePay',
        'dana'      => 'DANA',
        'gopay'     => 'GoPay',
        'ovo'       => 'OVO',
    ];

    protected $fillable = [
        'user_id',
        'car_model',
        'plate_number',
        'bank_name',
        'account_number',
        // Tujuan pencairan: 'bank' (BTN) atau 'ewallet'. Lihat migrasi
        // 2026_08_29_000001_add_ewallet_payout_to_driver_profiles.
        'payout_method',
        'ewallet_provider',
        'ewallet_number',
        'ewallet_holder_name',
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
