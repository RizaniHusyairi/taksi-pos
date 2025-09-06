<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CsoController extends Controller
{
        public function getAvailableDrivers()
    {
        // Ambil semua user yang role-nya driver, aktif, dan statusnya 'available'
        $availableDrivers = User::where('role', 'driver')
                                ->where('active', true)
                                ->where('status', 'available') // Asumsi ada kolom 'status' di tabel users
                                ->get(['id', 'name', 'car', 'plate']); // Ambil kolom yang perlu saja

        // Kirim response dalam format JSON
        return response()->json($availableDrivers);
    }
}
