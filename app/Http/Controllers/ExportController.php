<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Withdrawals;
use App\Models\Transaction;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

class ExportController extends Controller
{
    public function exportWithdrawalById($id)
    {
        // 1. Ambil Data Withdrawal dengan Relasi
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
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream("Pencairan-{$withdrawal->id}-{$withdrawal->driver->name}.pdf");
    }

    // =========================================================================
    // === Export Log Transaksi (PDF & Excel) — filter sama dengan tabel UI ===
    // =========================================================================

    private function filteredTransactions(Request $request)
    {
        return Transaction::filter($request)
            ->with(['booking.zoneTo', 'booking.driver', 'booking.cso'])
            ->orderBy('created_at', 'desc')
            ->limit(5000) // batas aman ukuran export
            ->get();
    }

    private function summary($txns): array
    {
        return [
            'count'       => $txns->count(),
            'total'       => (float) $txns->sum('amount'),
            'qris'        => (float) $txns->where('method', 'QRIS')->sum('amount'),
            'cash_cso'    => (float) $txns->where('method', 'CashCSO')->sum('amount'),
            'cash_driver' => (float) $txns->where('method', 'CashDriver')->sum('amount'),
        ];
    }

    private function filterContext(Request $request): array
    {
        $ctx = [];
        $from = $request->query('date_from');
        $to = $request->query('date_to');
        if ($from || $to) {
            $ctx['Periode'] = ($from ?: 'awal') . '  s/d  ' . ($to ?: 'sekarang');
        }
        if ($request->filled('method')) {
            $map = ['QRIS' => 'QRIS', 'CashCSO' => 'Tunai (Kasir)', 'CashDriver' => 'Tunai (Supir)'];
            $ctx['Metode'] = $map[$request->query('method')] ?? $request->query('method');
        }
        if ($request->filled('driver_id')) {
            $ctx['Supir'] = optional(User::find($request->query('driver_id')))->name ?? ('#' . $request->query('driver_id'));
        }
        if ($request->filled('cso_id')) {
            $ctx['CSO'] = optional(User::find($request->query('cso_id')))->name ?? ('#' . $request->query('cso_id'));
        }
        if ($request->filled('search')) {
            $ctx['Pencarian'] = '"' . $request->query('search') . '"';
        }
        return $ctx;
    }

    public function transactionsPdf(Request $request)
    {
        $txns = $this->filteredTransactions($request);
        $pdf = Pdf::loadView('pdf.transactions', [
            'transactions' => $txns,
            'summary'      => $this->summary($txns),
            'context'      => $this->filterContext($request),
            'generatedAt'  => now()->format('d M Y, H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('Laporan-Transaksi-' . now()->format('Ymd_His') . '.pdf');
    }

    public function transactionsExcel(Request $request)
    {
        $txns = $this->filteredTransactions($request);
        $html = view('excel.transactions', [
            'transactions' => $txns,
            'summary'      => $this->summary($txns),
            'context'      => $this->filterContext($request),
            'generatedAt'  => now()->format('d M Y, H:i'),
        ])->render();

        return response($html, 200, [
            'Content-Type'        => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="Laporan-Transaksi-' . now()->format('Ymd_His') . '.xls"',
        ]);
    }
}
