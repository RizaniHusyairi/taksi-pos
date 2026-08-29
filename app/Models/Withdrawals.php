<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;

class Withdrawals extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'driver_id',
        'amount',
        'status',
        'requested_at',
        'processed_at',
        'proof_image',
        // Salinan tujuan pencairan saat pengajuan dibuat — lihat migrasi
        // 2026_08_29_000002_add_payout_snapshot_to_withdrawals.
        'payout_method',
        'payout_provider',
        'payout_account',
        'payout_holder_name',
    ];

    /**
     * Ikut terkirim di setiap response JSON supaya panel admin, email, dan PDF
     * tidak masing-masing menyusun ulang teks tujuan pencairan.
     */
    protected $appends = ['payout_channel', 'payout_detail', 'payout_label'];

    /**
     * Mendefinisikan relasi bahwa setiap withdrawal DImiliki OLEH satu supir (User).
     */
    public function driver()
    {
        // 'driver_id' adalah foreign key di tabel withdrawals
        return $this->belongsTo(User::class, 'driver_id');
    }
    public function transactions()
    {
        return $this->hasMany(\App\Models\Transaction::class, 'withdrawal_id');
    }

    /**
     * Nama kanal tujuan, mis. "Bank BTN" atau "DANA".
     *
     * Pengajuan sebelum fitur e-wallet tidak punya snapshot, jadi jatuh kembali
     * ke profil driver — di situ tujuannya memang selalu bank.
     */
    public function getPayoutChannelAttribute(): ?string
    {
        if ($this->payout_method === 'ewallet') {
            return DriverProfile::EWALLET_PROVIDERS[$this->payout_provider]
                ?? $this->payout_provider;
        }

        if ($this->payout_method === 'bank') {
            return $this->payout_provider ?: 'Bank BTN';
        }

        return optional(optional($this->driver)->driverProfile)->bank_name;
    }

    /**
     * Nomor tujuan, dilengkapi nama pemilik untuk e-wallet agar admin bisa
     * mencocokkan sebelum transfer.
     */
    public function getPayoutDetailAttribute(): ?string
    {
        $nomor = $this->payout_account
            ?: optional(optional($this->driver)->driverProfile)->account_number;

        if (!$nomor) {
            return null;
        }

        return $this->payout_holder_name
            ? $nomor . ' a.n. ' . $this->payout_holder_name
            : $nomor;
    }

    /** Satu baris siap tampil, mis. "DANA — 081234567890 a.n. Budi". */
    public function getPayoutLabelAttribute(): ?string
    {
        $kanal  = $this->payout_channel;
        $detail = $this->payout_detail;

        if (!$kanal && !$detail) {
            return null;
        }

        return trim($kanal . ' — ' . $detail, ' —');
    }
}
