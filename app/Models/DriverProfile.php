<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverProfile extends Model
{
    protected $fillable = [
        'user_id', 
        'car_model', 
        'plate_number', 
        'bank_name', 
        'account_number', 
        'status',
        'line_number',
        'last_queue_date',
    ];

    public function user()
    {
        // Profil driver ini dimiliki oleh satu user
        return $this->belongsTo(User::class);
    }
}
