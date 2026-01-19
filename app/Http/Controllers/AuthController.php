<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;

class AuthController extends Controller
{
    public function login(Request $request): RedirectResponse
        {
        // 1. Validasi input dari form login
        $credentials = $request->validate([
        'username' => ['required', 'string'],
        'password' => ['required'],
        ]);

        // 2. Coba otentikasi pengguna dengan data yang diberikan
        if (Auth::attempt($credentials, true)) {
            // Jika berhasil, regenerate session untuk keamanan
            $request->session()->regenerate();

            // Ambil role pengguna yang berhasil login
         $userRole = Auth::user()->role;

         // 3. Arahkan pengguna ke dasbor yang sesuai dengan rolenya
         switch ($userRole) {
             case 'admin':
                 return redirect()->intended('/admin');
             case 'cso':
                 return redirect()->intended('/cso');
             case 'driver':
                 return redirect()->intended('/driver');
             default:
                 // Jika role tidak ada, fallback ke halaman utama
                 return redirect()->intended('/');
         }
        
        }
        // 4. Jika otentikasi gagal, kembalikan ke halaman login dengan pesan error
        return back()->withErrors([
        'username' => 'Username atau password yang Anda masukkan salah.',
        ])->onlyInput('username');
        
    }

    public function logout(Request $request): RedirectResponse
    {
        // Hapus sesi otentikasi pengguna
        Auth::logout();

        // Invalidate session lama
        $request->session()->invalidate();

        // Buat ulang token CSRF untuk keamanan
        $request->session()->regenerateToken();

        // Arahkan kembali ke halaman login
        return redirect('/');
    }
}
