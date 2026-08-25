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
        // Middleware ini juga dipasang di rute API (guard sanctum). Di sana
        // tidak ada sesi web untuk ditutup dan mengarahkan klien API ke sebuah
        // halaman HTML tidak ada gunanya — jawab 403 saja.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Akses ditolak: peran Anda tidak berhak atas sumber daya ini.',
            ], 403);
        }

        // Admin satu-satunya peran yang punya halaman web. CSO/driver (dan role
        // tak terdefinisi) tidak boleh menyisakan sesi menggantung: tutup saja.
        if ($user->role === 'admin') {
            return redirect('/admin');
        }

        // Halaman login ada di '/' (route name: login), bukan '/login'.
        Auth::logout();
        return redirect()->route('login');
    }
}