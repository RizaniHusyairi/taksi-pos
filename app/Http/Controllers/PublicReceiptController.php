<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Rating;
use Illuminate\Http\Request;

class PublicReceiptController extends Controller
{
    public function show($uuid)
    {
        $transaction = Transaction::with([
            'booking.zoneTo',
            'booking.driver.driverProfile',
            'booking.cso',
        ])->where('receipt_token', $uuid)->firstOrFail();

        $rating = $transaction->booking_id
            ? Rating::where('booking_id', $transaction->booking_id)->first()
            : null;

        return view('public_receipt', compact('transaction', 'rating'));
    }

    public function rate(Request $request, $uuid)
    {
        $transaction = Transaction::where('receipt_token', $uuid)->firstOrFail();

        if (!$transaction->booking_id) {
            return response()->json(['message' => 'Struk ini tidak bisa dinilai.'], 422);
        }

        $validated = $request->validate([
            'stars'   => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        // Satu penilaian per trip (booking). firstOrCreate mencegah rating ganda.
        $rating = Rating::firstOrCreate(
            ['booking_id' => $transaction->booking_id],
            ['stars' => $validated['stars'], 'comment' => $validated['comment'] ?? null]
        );

        if (!$rating->wasRecentlyCreated) {
            return response()->json([
                'message' => 'Penilaian untuk perjalanan ini sudah pernah dikirim. Terima kasih! 🙏',
                'already' => true,
            ]);
        }

        return response()->json(['message' => 'Terima kasih atas penilaian Anda! 🙏'], 201);
    }
}
