<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;

/**
 * Autentikasi Management API via API key (bukan login user).
 * Kirim: `Authorization: Bearer <api_key>`.
 */
class ManagementApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $bearer = $request->bearerToken();

        if (!$bearer) {
            return response()->json([
                'message' => 'API key diperlukan. Kirim header Authorization: Bearer <api_key>.',
            ], 401);
        }

        $client = ApiClient::where('key_hash', hash('sha256', $bearer))
            ->where('active', true)
            ->first();

        if (!$client) {
            return response()->json(['message' => 'API key tidak valid atau sudah dicabut.'], 401);
        }

        // Catat pemakaian terakhir tanpa menyentuh updated_at.
        ApiClient::whereKey($client->id)->update(['last_used_at' => now()]);

        $request->attributes->set('api_client', $client);

        return $next($request);
    }
}
