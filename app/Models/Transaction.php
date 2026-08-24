<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Http\Request;


class Transaction extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'booking_id',
        'method',
        'payment_proof',
        'withdrawal_id',
        'payout_status',
        'amount',
        'receipt_token',
        // Buku besar KEDUA: setoran tunai CSO ke admin. Sengaja terpisah dari
        // payout_status/withdrawal_id yang mengurus hak supir — lihat migrasi
        // 2026_08_24_000002_add_deposit_columns_to_transactions_table.
        'cso_deposit_id',
        'deposit_status',
    ];

    /**
     * Setiap transaksi baru otomatis dapat token acak untuk URL struk.
     */
    protected static function booted(): void
    {
        static::creating(function (Transaction $t) {
            if (empty($t->receipt_token)) {
                $t->receipt_token = \Illuminate\Support\Str::random(40);
            }
        });
    }

    /**
     * Relasi bahwa setiap transaksi dimiliki oleh satu booking.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Setoran CSO yang mencakup transaksi ini (null = belum pernah disetor).
     */
    public function csoDeposit(): BelongsTo
    {
        return $this->belongsTo(CsoDeposit::class, 'cso_deposit_id');
    }

    /**
     * Tunai yang MASIH dipegang CSO dan wajib disetor ke admin.
     *
     * Satu-satunya definisi "belum disetor" di seluruh aplikasi — dipakai
     * rekap per tanggal maupun saat setoran dibuat, supaya angka yang dilihat
     * CSO dan angka yang dikunci server tidak mungkin berbeda aturan.
     *
     * Booking 'Cancelled' dikeluarkan: uangnya tidak pernah jadi milik koperasi.
     */
    public function scopeCashCsoBelumSetor($query, int $csoId)
    {
        return $query->where('method', 'CashCSO')
            ->where('deposit_status', 'Unsettled')
            ->whereHas('booking', function ($b) use ($csoId) {
                $b->where('cso_id', $csoId)->where('status', '!=', 'Cancelled');
            });
    }

    /**
     * Relasi bahwa setiap transaksi dimiliki oleh satu supir melalui booking.
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /**
     * Relasi bahwa setiap transaksi dibuat oleh satu CSO melalui booking.
     */
    public function cso(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cso_id');
    }

    /**
     * Filter bersama (dipakai daftar transaksi & export) berdasar query string:
     * date_from, date_to, method, driver_id, cso_id, search.
     */
    public function scopeFilter($query, Request $request)
    {
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }
        if ($request->filled('method')) {
            $query->where('method', $request->query('method'));
        }
        if ($request->filled('driver_id')) {
            $query->whereHas('booking', fn ($q) => $q->where('driver_id', $request->query('driver_id')));
        }
        if ($request->filled('cso_id')) {
            $query->whereHas('booking', fn ($q) => $q->where('cso_id', $request->query('cso_id')));
        }
        if ($request->filled('search')) {
            $s = trim($request->query('search'));
            $query->where(function ($q) use ($s) {
                $q->whereHas('booking.driver', fn ($d) => $d->where('name', 'like', "%{$s}%"))
                  ->orWhereHas('booking.cso', fn ($c) => $c->where('name', 'like', "%{$s}%"))
                  ->orWhereHas('booking.zoneTo', fn ($z) => $z->where('name', 'like', "%{$s}%"))
                  ->orWhereHas('booking', fn ($b) => $b->where('manual_destination', 'like', "%{$s}%"));
                if (is_numeric($s)) {
                    $q->orWhere('booking_id', (int) $s);
                }
            });
        }
        return $query;
    }
}
