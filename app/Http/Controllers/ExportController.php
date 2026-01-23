<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Withdrawals;
use Barryvdh\DomPDF\Facade\Pdf;

class ExportController extends Controller
{
    public function exportWithdrawalById($id)
    {
        // 1. Ambil Data Withdrawal dengan Relasi
        // Pastikan user admin yg akses (Middleware dicek di route)
        $withdrawal = Withdrawals::with(['driver.driverProfile', 'transactions.booking.zoneTo'])
            ->findOrFail($id);

        // 2. Siapkan Data untuk View
        $data = [
            'withdrawal' => $withdrawal,
            'driver' => $withdrawal->driver,
            'transactions' => $withdrawal->transactions,
            'date' => now()->format('d M Y H:i'),
        ];

        // 3. Generate PDF
        $pdf = Pdf::loadView('pdf.withdrawal', $data);

        // 4. Stream / Download
        // Set paper size A4 portrait
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream("Pencairan-{$withdrawal->id}-{$withdrawal->driver->name}.pdf");
    }
}
