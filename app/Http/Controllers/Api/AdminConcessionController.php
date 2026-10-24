<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConcessionPayment;
use App\Models\DriverConcession;
use App\Models\Setting;
use App\Models\User;
use App\Services\ConcessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Panel admin "Konsesi Supir": siapa sudah bayar berapa untuk satu bulan,
 * plus dua tindakan admin — mencatat bayar tunai dan membebaskan tagihan.
 */
class AdminConcessionController extends Controller
{
    public function __construct(private ConcessionService $konsesi)
    {
    }

    public function index(Request $request)
    {
        $request->validate(['period' => 'nullable|date_format:Y-m']);
        $period = $request->input('period') ?: ConcessionService::periodNow();

        $drivers = User::where('role', 'driver')
            ->where('active', true)
            ->with('driverProfile:user_id,line_number,plate_number')
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'active', 'role']);

        // Bulan berjalan: terbitkan tagihan yang belum ada supaya daftar
        // admin lengkap walaupun scheduler belum sempat jalan.
        if ($period === ConcessionService::periodNow()) {
            foreach ($drivers as $d) {
                $this->konsesi->issue($d, $period);
            }
        }

        $tagihan = DriverConcession::where('period', $period)
            ->withSum('payments as paid', 'amount')
            ->withMax('payments as last_paid_at', 'created_at')
            ->get()
            ->keyBy('driver_id');

        // Tunggakan bulan-bulan SEBELUMNYA yang masih Open.
        $tunggakan = DriverConcession::where('period', '<', $period)
            ->where('status', 'Open')
            ->withSum('payments as paid', 'amount')
            ->get()
            ->groupBy('driver_id')
            ->map(fn ($rows) => $rows->sum(fn ($c) => max(0, $c->amount - (int) $c->paid)));

        $rows = $drivers->map(function (User $d) use ($tagihan, $tunggakan) {
            $c = $tagihan->get($d->id);
            $paid = (int) ($c->paid ?? 0);

            return [
                'driver_id'    => $d->id,
                'name'         => $d->name,
                'username'     => $d->username,
                'line_number'  => $d->driverProfile?->line_number,
                'plate_number' => $d->driverProfile?->plate_number,
                'concession_id' => $c?->id,
                'amount'       => $c?->amount,
                'paid'         => $paid,
                'remaining'    => $c && $c->status === 'Open' ? max(0, $c->amount - $paid) : 0,
                'status'       => $c?->status,
                'blocked'      => $c ? $this->konsesi->isBlocked($c, $paid) : false,
                'last_paid_at' => $c?->last_paid_at,
                'arrears'      => (int) ($tunggakan->get($d->id) ?? 0),
            ];
        })->values();

        $aktif = $rows->whereNotNull('concession_id');

        return response()->json([
            'period'       => $period,
            'period_label' => ConcessionService::periodLabel($period),
            'fee'          => Setting::concessionFee(),
            'summary'      => [
                'billed'    => (int) $aktif->where('status', '!=', 'Waived')->sum('amount'),
                'collected' => (int) $aktif->sum('paid'),
                'remaining' => (int) $aktif->sum('remaining'),
                'paid_off'  => $aktif->where('status', 'Paid')->count(),
                'blocked'   => $aktif->where('blocked', true)->count(),
                'drivers'   => $aktif->count(),
            ],
            'data' => $rows,
        ]);
    }

    public function show(DriverConcession $concession)
    {
        $concession->load([
            'driver:id,name,username',
            'waivedBy:id,name',
            'payments' => fn ($q) => $q->latest()->with('recordedBy:id,name'),
        ]);

        $paid = (int) $concession->payments->sum('amount');

        return response()->json([
            'id'           => $concession->id,
            'driver'       => $concession->driver,
            'period'       => $concession->period,
            'period_label' => ConcessionService::periodLabel($concession->period),
            'amount'       => $concession->amount,
            'paid'         => $paid,
            'remaining'    => $concession->status === 'Open' ? max(0, $concession->amount - $paid) : 0,
            'status'       => $concession->status,
            'waived_by'    => $concession->waivedBy?->name,
            'waive_note'   => $concession->waive_note,
            'payments'     => $concession->payments->map(fn (ConcessionPayment $p) => [
                'amount'      => $p->amount,
                'source'      => $p->source,
                'note'        => $p->note,
                'recorded_by' => $p->recordedBy?->name,
                'withdrawal_id' => $p->withdrawal_id,
                'created_at'  => $p->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Supir bayar tunai langsung ke admin. Jalan keluar wajib bagi supir yang
     * saldonya nol/negatif (mis. semua pendapatannya tunai di tangan sendiri)
     * — tanpa ini mereka terkunci dari antrian tanpa cara membayar.
     */
    public function recordCash(Request $request, DriverConcession $concession)
    {
        $validated = $request->validate([
            'amount' => 'required|integer|min:1|max:100000000',
            'note'   => 'nullable|string|max:500',
        ]);

        $hasil = $this->konsesi->pay(
            $concession,
            (int) $validated['amount'],
            'cash_admin',
            null,
            Auth::id(),
            $validated['note'] ?? null,
        );

        if (is_string($hasil)) {
            return response()->json(['message' => $hasil], 422);
        }

        return response()->json(['message' => 'Pembayaran tunai konsesi tercatat.']);
    }

    /** Bebaskan tagihan (cuti, mobil di bengkel, dsb). Catatan wajib. */
    public function waive(Request $request, DriverConcession $concession)
    {
        $validated = $request->validate([
            'note' => 'required|string|max:500',
        ], [
            'note.required' => 'Tulis alasan pembebasan konsesi.',
        ]);

        if ($concession->status !== 'Open') {
            return response()->json([
                'message' => 'Konsesi ini sudah ' . ($concession->status === 'Paid' ? 'lunas.' : 'dibebaskan.'),
            ], 422);
        }

        $concession->update([
            'status'     => 'Waived',
            'waived_by'  => Auth::id(),
            'waive_note' => $validated['note'],
        ]);

        return response()->json(['message' => 'Konsesi dibebaskan.']);
    }
}
