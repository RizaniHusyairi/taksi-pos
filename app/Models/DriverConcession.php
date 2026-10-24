<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tagihan konsesi satu supir untuk satu bulan (`period` = YYYY-MM).
 * Lihat migrasi 2026_10_24_000002 dan App\Services\ConcessionService.
 */
class DriverConcession extends Model
{
    protected $fillable = [
        'driver_id',
        'period',
        'amount',
        'status',
        'waived_by',
        'waive_note',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ConcessionPayment::class, 'concession_id');
    }
}
