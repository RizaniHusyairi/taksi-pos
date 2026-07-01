<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawals;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Management API (read-only) untuk sistem eksternal — website manajemen koperasi.
 * Auth via API key (middleware `management.api`). Semua endpoint HANYA membaca.
 */
class ManagementApiController extends Controller
{
    /** Verifikasi key + info sistem. */
    public function me(Request $request)
    {
        $client = $request->attributes->get('api_client');

        return response()->json([
            'system'      => config('app.name'),
            'client'      => $client?->name,
            'scope'       => 'read-only',
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** Daftar transaksi (paginasi + filter tanggal/metode/supir/cso/search). */
    public function transactions(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 50), 200);

        $page = Transaction::with([
                'booking.driver:id,name',
                'booking.cso:id,name',
                'booking.zoneTo:id,name',
            ])
            ->filter($request)
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(function ($t) {
                $b = $t->booking;
                return [
                    'id'            => $t->id,
                    'booking_id'    => $t->booking_id,
                    'date'          => $this->iso($t->created_at),
                    'method'        => $t->method,
                    'amount'        => (float) $t->amount,
                    'payout_status' => $t->payout_status,
                    'destination'   => optional(optional($b)->zoneTo)->name
                                        ?? optional($b)->manual_destination,
                    'driver'        => optional(optional($b)->driver)->name,
                    'cso'           => optional(optional($b)->cso)->name,
                ];
            }),
            'meta' => $this->pageMeta($page),
        ]);
    }

    /** Ringkasan pendapatan + estimasi komisi koperasi (filter rentang tanggal). */
    public function revenueSummary(Request $request)
    {
        $base = Transaction::query();
        if ($request->filled('date_from')) {
            $base->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $base->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $gross = (float) (clone $base)->sum('amount');
        $count = (clone $base)->count();
        $rate  = (float) Setting::getValue('commission_rate') ?: 0.2;

        $byMethod = (clone $base)
            ->selectRaw('method, COUNT(*) as trx, SUM(amount) as total')
            ->groupBy('method')
            ->get()
            ->map(fn ($r) => [
                'method' => $r->method,
                'count'  => (int) $r->trx,
                'total'  => round((float) $r->total),
            ])
            ->values();

        return response()->json([
            'range'               => [
                'from' => $request->query('date_from'),
                'to'   => $request->query('date_to'),
            ],
            'transactions'        => $count,
            'gross_revenue'       => round($gross),
            'commission_rate'     => $rate,
            'commission_estimate' => round($gross * $rate),
            'by_method'           => $byMethod,
        ]);
    }

    /** Daftar supir (anggota koperasi) + statistik ringkas. */
    public function drivers(Request $request)
    {
        $drivers = User::where('role', 'driver')
            ->with('driverProfile')
            ->withCount(['bookings as total_trips' => fn ($q) => $q->where('status', 'Completed')])
            ->orderBy('name')
            ->get()
            ->map(fn ($u) => [
                'id'           => $u->id,
                'name'         => $u->name,
                'phone'        => $u->phone_number,
                'line_number'  => optional($u->driverProfile)->line_number,
                'car_model'    => optional($u->driverProfile)->car_model,
                'plate_number' => optional($u->driverProfile)->plate_number,
                'status'       => optional($u->driverProfile)->status,
                'total_trips'  => $u->total_trips,
                'active'       => (bool) $u->active,
            ])
            ->values();

        return response()->json(['data' => $drivers]);
    }

    /** Daftar pencairan dana ke supir (paginasi + filter status). */
    public function withdrawals(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 50), 200);

        $q = Withdrawals::with('driver:id,name')->latest();
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        $page = $q->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn ($w) => [
                'id'           => $w->id,
                'driver'       => optional($w->driver)->name,
                'amount'       => (float) $w->amount,
                'status'       => $w->status,
                'requested_at' => $this->iso($w->requested_at ?? $w->created_at),
                'processed_at' => $this->iso($w->processed_at),
            ]),
            'meta' => $this->pageMeta($page),
        ]);
    }

    private function iso($value): ?string
    {
        return empty($value) ? null : Carbon::parse($value)->toIso8601String();
    }

    private function pageMeta($page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'per_page'     => $page->perPage(),
            'total'        => $page->total(),
            'last_page'    => $page->lastPage(),
        ];
    }
}
