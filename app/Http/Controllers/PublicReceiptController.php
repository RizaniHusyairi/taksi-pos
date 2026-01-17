<?php
namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use App\Models\Transaction;

class PublicReceiptController extends Controller
{
    public function show($uuid)
    {
        // Cari transaksi berdasarkan UUID atau ID (disarankan tambah kolom UUID di tabel transactions agar aman, 
        // tapi untuk sekarang kita pakai ID yang di-encode base64 atau ID biasa jika Anda mau simpel)
        
        // Asumsi: $uuid adalah ID transaksi
        $transaction = Transaction::with(['booking.zoneTo', 'booking.driver', 'cso'])->findOrFail($uuid);
        
        return view('public_receipt', compact('transaction'));
    }
}