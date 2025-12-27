<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverQueue extends Model
{
    protected $guarded = ['id'];

    public function driver()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
