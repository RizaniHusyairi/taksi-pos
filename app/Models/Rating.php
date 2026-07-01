<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Booking;

class Rating extends Model
{
    protected $fillable = ['booking_id', 'stars', 'comment'];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
