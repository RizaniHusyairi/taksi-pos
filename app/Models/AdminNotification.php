<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu peristiwa yang perlu diketahui admin, tampil di ikon lonceng header.
 *
 * Ditulis lewat App\Services\AdminNotifier — jangan membuat baris di sini
 * langsung dari controller, supaya jaminan "gagal menulis notifikasi tidak
 * boleh membatalkan aksi utama" tetap terpusat di satu tempat.
 */
class AdminNotification extends Model
{
    protected $fillable = [
        'type',
        'title',
        'body',
        'link',
        'level',
        'related_type',
        'related_id',
    ];

    /** Jenis yang dikenali; dipakai juga untuk memetakan hitungan antrean. */
    public const TYPE_WITHDRAWAL     = 'withdrawal';
    public const TYPE_CSO_DEPOSIT    = 'cso_deposit';
    public const TYPE_DRIVER_DEPOSIT = 'driver_deposit';
    public const TYPE_METHOD_DISPUTE = 'method_dispute';

    /**
     * Notifikasi yang lahir setelah admin terakhir membuka lonceng.
     *
     * `$sejak` null berarti belum pernah membuka sama sekali — seluruhnya
     * dihitung belum dibaca.
     */
    public function scopeBelumDibaca($query, ?\DateTimeInterface $sejak)
    {
        return $sejak ? $query->where('created_at', '>', $sejak) : $query;
    }
}
