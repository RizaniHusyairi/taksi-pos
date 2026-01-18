<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Jika belum login, biarkan middleware auth yang menangani
        if (!Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();

        // Jika user dinonaktifkan
        if (!$user->active) {

            // Logout untuk membersihkan session
            Auth::logout();

            // Invalidate session lama
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Bedakan respons Web dan API
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Akun Anda telah dinonaktifkan.'
                ], 403);
            }

            // Untuk Web (Blade)
            return redirect('/login')
                ->withErrors([
                    'username' => 'Akun Anda telah dinonaktifkan oleh admin.'
                ]);
        }

        return $next($request);
    }
}
