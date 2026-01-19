<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    /**
    * Menampilkan halaman login.
    */
    public function showLogin(): View
    {
        // Fungsi ini hanya mengembalikan view 'login.blade.php'
        return view('login');
    }

    /**
    *Menampilkan dasbor Admin.
    */
    public function showAdmin(): View
    {
        // Mengembalikan view 'admin.blade.php'
        return view('pages.admin');
    }

    /**

    * Menampilkan dasbor CSO.
    */
    public function showCso(): View
    {
        // Ambil Global QRIS
        $qrisPath = \App\Models\Setting::where('key', 'company_qris_path')->value('value');
        $qrisUrl = $qrisPath ? asset('storage/' . $qrisPath) : null;

        return view('pages.cso', ['companyQrisUrl' => $qrisUrl]);
    }

    /**

    * Menampilkan dasbor Driver.
    */
    public function showDriver(): View
    {
        // Mengembalikan view 'driver.blade.php'
        return view('pages.driver');
    }


}



