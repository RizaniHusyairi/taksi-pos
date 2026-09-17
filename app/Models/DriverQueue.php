<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DriverQueue extends Model
{
    protected $guarded = ['id'];

    protected $fillable = [
        'user_id',
        'latitude',
        'longitude',
        'sort_order', // <--- Tambahkan ini
        'heads_up_level',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Antrian supir yang BENAR-BENAR siap menerima order, terurut giliran:
     * sort_order ASC (0,1,2 ... 1000+ = rejoin), lalu created_at ASC
     * (sesama sort_order: siapa cepat dia dapat).
     *
     * Satu-satunya definisi "giliran berikutnya" — dipakai CsoApiController
     * (daftar supir, processOrder, changeDriver) dan QueueHeadsUpService
     * supaya peringatan pra-giliran tidak pernah menunjuk supir yang berbeda
     * dari yang akan dipilih CSO.
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query
            ->whereHas('driver.driverProfile', function ($profile) {
                // Hanya driver yang BENAR-BENAR siap: sudah tiba & standby, dan tidak
                // sedang di luar area. Cegah order jatuh ke driver yang belum datang
                // (mis. hasil pre-fill rotasi harian yang masih offline & lat/lng 0).
                $profile->whereIn('status', ['standby', 'available'])
                      ->whereNull('out_of_area_since')
                      // ...DAN masih mengirim kabar. Tanpa syarat ini, supir
                      // yang mematikan aplikasi lalu pulang tetap memegang
                      // gilirannya: `out_of_area_since` hanya terisi kalau ada
                      // ping, jadi HP yang diam terlihat sama seperti supir
                      // yang setia menunggu di bandara. Aplikasi mengirim
                      // heartbeat tiap 75 detik, jadi ambang menit-an ini tidak
                      // akan mengganggu supir yang benar-benar hadir.
                      ->whereNotNull('location_updated_at')
                      ->where('location_updated_at', '>=', now()->subMinutes(
                          (int) config('taksi.driver_queue.stale_location_minutes')
                      ));
            })
            ->orderBy('sort_order', 'asc')
            ->orderBy('created_at', 'asc');
    }
}
