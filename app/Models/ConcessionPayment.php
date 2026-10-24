<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu cicilan konsesi. `source = wallet` dengan `withdrawal_id` null berarti
 * potongan yang masih mengurangi saldo dompet supir (belum ikut pencairan).
 */
class ConcessionPayment extends Model
{
    protected $fillable = [
        'concession_id',
        'driver_id',
        'amount',
        'source',
        'withdrawal_id',
        'transaction_id',
        'recorded_by',
        'note',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function concession(): BelongsTo
    {
        return $this->belongsTo(DriverConcession::class, 'concession_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Potongan dompet yang masih mengurangi saldo (belum terkunci pencairan). */
    public function scopePotonganDompetTerbuka($q, int $driverId)
    {
        return $q->where('driver_id', $driverId)
            ->where('source', 'wallet')
            ->whereNull('withdrawal_id');
    }
}
