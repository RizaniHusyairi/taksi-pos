<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Http\Controllers\Controller;

class CsoApiController extends Controller
{
    public function getAvailableDrivers()
    {
        $drivers = User::where('role', 'driver')
                       ->where('active', true)
                       ->where('status', 'available')
                       ->get(['id', 'name', 'car', 'plate']);

        return response()->json($drivers);
    }
}