<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;


class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role)
    {
        // 1. Cek apakah pengguna sudah login
        if (!Auth::check()) {
            // Jika belum login, arahkan ke halaman login
            return redirect('login');
        }   

        // 2. Ambil data pengguna yang sedang login
        $user = Auth::user();
        // 3. Cek apakah role pengguna sesuai dengan role yang dibutuhkan oleh rute
        if ($user->role === $role) {
            // Jika sesuai, izinkan permintaan untuk melanjutkan ke tujuan (Controller/View)
            return $next($request);
        }

        // 4. Jika tidak sesuai, alihkan pengguna ke dasbornya masing-masing
        //    Ini lebih baik daripada hanya menampilkan error "Forbidden".
        switch ($user->role) {
            case 'admin':
                return redirect('/admin');
            case 'cso':
                return redirect('/cso');
            case 'driver':
                return redirect('/driver');
            default:
                // Jika rolenya tidak terdefinisi, logout saja untuk keamanan
                Auth::logout();
                return redirect('/login');
        }
    }
}