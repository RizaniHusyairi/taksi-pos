<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\DriverProfile;
use App\Models\Withdrawals;
use App\Models\Booking;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany; // <-- Tambahkan ini jika mau
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;


class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function driverProfile()
    {
        // Seorang user memiliki satu profil driver
        return $this->hasOne(DriverProfile::class);
    }

    // =========================================================
    // === TAMBAHKAN FUNGSI INI ===
    // =========================================================
    /**
     * Menentukan kolom yang digunakan untuk otentikasi.
     *
     * @return string
     */
    public function username()
    {
        return 'username';
    }

    /**
     * Mendefinisikan relasi bahwa seorang User (supir) MEMILIKI BANYAK withdrawals.
     */
    public function withdrawals()
    {
        return $this->hasMany(Withdrawals::class, 'driver_id');
    }

        /**
     * Mendefinisikan relasi bahwa seorang User (supir) MEMILIKI BANYAK booking.
     * Metode ini dibutuhkan oleh fitur seperti withCount('bookings') di laporan.
     */
    public function bookings(): HasMany
    {
        // Foreign key di tabel 'bookings' adalah 'driver_id'
        return $this->hasMany(Booking::class, 'driver_id');
    }

    /**
     * Mendefinisikan relasi bahwa seorang User (supir) MEMILIKI BANYAK transaksi.
     * Ini dibutuhkan oleh fitur seperti withSum('transactions') di laporan.
     */
    public function transactions(): HasManyThrough
    {
        // Untuk menemukan transaksi milik user ini, kita perlu melalui tabel 'bookings'.
        // Jadi kita menggunakan relasi HasManyThrough.
        return $this->hasManyThrough(
            Transaction::class, // Model tujuan akhir
            Booking::class,     // Model perantara
            'driver_id',        // Foreign key di tabel 'bookings' (menghubungkan ke User)
            'booking_id',       // Foreign key di tabel 'transactions' (menghubungkan ke Booking)
            'id',               // Local key di tabel 'users'
            'id'                // Local key di tabel 'bookings'
        );
    }
}
