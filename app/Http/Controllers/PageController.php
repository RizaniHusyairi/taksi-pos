<?php

namespace App\Http\Controllers;

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
}



