<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kali serah terima uang tunai dari CSO ke admin.
 *
 * Mencakup satu atau beberapa TANGGAL sekaligus — CSO memilih tanggalnya,
 * server yang menjumlahkan nominalnya dari tabel transactions.
 * Alur status meniru Pencairan Dana supir: Pending -> Approved | Rejected,
 * dan hanya yang masih Pending yang boleh diproses admin.
 */
class CsoDeposit extends Model
{
    protected $fillable = [
        'cso_id',
        'amount',
        'status',
        'period_dates',
        'transactions_count',
        'note',
        'admin_note',
        'proof_image',
        'submitted_at',
        'processed_at',
        'processed_by',
    ];

    protected $casts = [
        'period_dates' => 'array',
        'amount'       => 'decimal:2',
        'submitted_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function cso(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cso_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /** Transaksi tunai yang tercakup dalam setoran ini. */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'cso_deposit_id');
    }
}
