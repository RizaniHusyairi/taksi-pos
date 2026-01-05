<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverQueue extends Model
{
    protected $guarded = ['id'];

    protected $fillable = [
        'user_id', 
        'latitude', 
        'longitude', 
        'sort_order', // <--- Tambahkan ini
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
