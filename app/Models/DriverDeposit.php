<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kali serah terima uang tunai dari SUPIR ke admin — pelunasan utang
 * komisi atas order yang dibayar tunai langsung ke supir.
 *
 * Bentuknya sengaja dibuat kembar dengan [CsoDeposit] (pilih tanggal, server
 * yang menjumlahkan, Pending -> Approved | Rejected, hanya Pending yang boleh
 * diproses) supaya admin tidak perlu mempelajari dua alur verifikasi yang
 * berbeda. Perbedaannya ada satu dan penting: `amount` di sini adalah FEE yang
 * terutang, bukan tarif kotor — supir menyetor bagian koperasi, bukan seluruh
 * ongkos penumpang. Lihat migrasi 2026_08_24_000005.
 */
class DriverDeposit extends Model
{
    protected $fillable = [
        'driver_id',
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

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /** Transaksi tunai-ke-supir yang utangnya dilunasi oleh setoran ini. */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'driver_deposit_id');
    }
}
