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
        // Setoran tunai SUPIR yang melunasi utang komisi transaksi ini.
        // Berbagi `payout_status` dengan pencairan — lihat migrasi
        // 2026_08_24_000005 untuk alasannya.
        'driver_deposit_id',
        // Sengketa metode pembayaran — lihat migrasi 2026_08_24_000006.
        'method_dispute_status',
        'method_dispute_note',
        'method_disputed_at',
        'method_admin_note',
        'method_resolved_at',
        'method_resolved_by',
        'original_method',
    ];

    protected $casts = [
        'method_disputed_at' => 'datetime',
        'method_resolved_at' => 'datetime',
    ];

    /**
     * Transaksi yang metodenya BOLEH disanggah supir.
     *
     * Tiga syarat, masing-masing punya alasan:
     *  - method 'CashDriver' — hanya arah ini yang masuk akal disanggah:
     *    sistem mengklaim supir memegang uangnya, dan supir bilang tidak.
     *  - payout_status 'Unpaid' — belum diselesaikan lewat pencairan maupun
     *    setoran. Mengoreksi metode transaksi yang bukunya sudah tutup akan
     *    mengubah angka yang sudah dibayarkan; itu koreksi manual admin,
     *    bukan sesuatu yang boleh dipicu dari aplikasi supir.
     *  - belum pernah disengketakan — satu suara per transaksi.
     */
    public function scopeBisaDisanggah($query)
    {
        return $query->where('method', 'CashDriver')
            ->where('payout_status', 'Unpaid')
            ->whereNull('method_dispute_status');
    }

    /**
     * Utang komisi supir yang BELUM dilunasi — baik lewat pencairan maupun
     * setoran tunai.
     *
     * Satu-satunya definisi "utang supir belum lunas", dipakai rekap per
     * tanggal maupun saat setoran dibuat, supaya angka yang dilihat supir dan
     * angka yang dikunci server tidak mungkin berbeda aturan. Bandingkan
     * [scopeCashCsoBelumSetor] yang melakukan hal sama untuk sisi CSO.
     *
     * Hanya booking 'Completed' yang dihitung: trip yang belum selesai belum
     * melahirkan kewajiban apa pun.
     */
    public function scopeCashDriverBelumLunas($query, int $driverId)
    {
        return $query->cashDriverOutstanding()
            ->whereHas('booking', fn ($b) => $b->where('driver_id', $driverId));
    }

    /**
     * Sama seperti [scopeCashDriverBelumLunas] tetapi untuk SELURUH supir —
     * dipakai Laporan Pendapatan admin untuk menghitung uang tunai yang masih
     * ada di tangan supir. Sengaja dibuat sebagai induk dari scope per-supir:
     * menulis kriterianya dua kali adalah cara termudah membuat angka admin
     * dan angka di aplikasi supir diam-diam berbeda.
     */
    public function scopeCashDriverOutstanding($query)
    {
        return $query->where('method', 'CashDriver')
            ->where('payout_status', 'Unpaid')
            ->whereHas('booking', fn ($b) => $b->where('status', 'Completed'));
    }

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
        return $query->cashCsoOutstanding()
            ->whereHas('booking', fn ($b) => $b->where('cso_id', $csoId));
    }

    /**
     * Sama seperti [scopeCashCsoBelumSetor] tetapi untuk SELURUH CSO — dipakai
     * Laporan Pendapatan admin untuk menghitung uang tunai yang masih ada di
     * tangan kasir. Induk dari scope per-CSO, dengan alasan yang sama seperti
     * [scopeCashDriverOutstanding].
     */
    public function scopeCashCsoOutstanding($query)
    {
        return $query->where('method', 'CashCSO')
            ->where('deposit_status', 'Unsettled')
            ->whereHas('booking', fn ($b) => $b->where('status', '!=', 'Cancelled'));
    }

    /**
     * Tunai CSO yang sudah diajukan setorannya tetapi belum diverifikasi admin.
     * Uangnya secara fisik sudah berpindah/diklaim, namun belum diakui — perlu
     * ditampilkan terpisah agar tidak tercampur dengan yang benar-benar masih
     * mengendap di kasir.
     */
    public function scopeCashCsoProcessing($query)
    {
        return $query->where('method', 'CashCSO')
            ->where('deposit_status', 'Processing')
            ->whereHas('booking', fn ($b) => $b->where('status', '!=', 'Cancelled'));
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
