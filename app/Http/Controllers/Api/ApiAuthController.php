<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class ApiAuthController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($request->only('username', 'password'))) {
            return response()->json([
                'message' => 'Username atau password salah.'
            ], 401);
        }

        $user = Auth::user();

        // Akun yang dinonaktifkan admin tidak boleh masuk.
        if (!$user->active) {
            Auth::logout();
            return response()->json([
                'message' => 'Akun Anda dinonaktifkan. Silakan hubungi admin.'
            ], 403);
        }

        // Aplikasi mobile hanya untuk role driver & cso. Admin tetap lewat web.
        if (!in_array($user->role, ['driver', 'cso'])) {
            Auth::logout();
            return response()->json([
                'message' => 'Akun ini tidak memiliki akses ke aplikasi mobile.'
            ], 403);
        }

        // Hapus token lama jika ingin single session, atau biarkan multi-device
        // $user->tokens()->delete();

        // Buat token baru
        $token = $user->createToken('mobile_app')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ]);
    }

    /**
     * Log the user out (Invalidate the token).
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Berhasil logout.'
        ]);
    }
}
