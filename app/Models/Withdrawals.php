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
    ];

    /**
     * Mendefinisikan relasi bahwa setiap withdrawal DImiliki OLEH satu supir (User).
     */
    public function driver()
    {
        // 'driver_id' adalah foreign key di tabel withdrawals
        return $this->belongsTo(User::class, 'driver_id');
    }
}
