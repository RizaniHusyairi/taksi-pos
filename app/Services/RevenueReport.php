<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penyusun angka Laporan Pendapatan admin.
 *
 * Dipisah dari controller karena dipakai DUA pintu: endpoint JSON untuk
 * tampilan, dan ExportController untuk PDF/Excel. Kalau masing-masing
 * menghitung sendiri, cepat atau lambat angka di layar dan angka di berkas
 * unduhan akan berbeda.
 *
 * Catatan definisi yang sengaja diambil:
 *
 *  - PENDAPATAN dihitung dari seluruh transaksi dalam rentang, tanpa
 *    mengecualikan booking 'Cancelled'. Ini mempertahankan perilaku laporan
 *    lama dan Log Transaksi, supaya angka lama tidak berubah diam-diam.
 *  - POSISI KAS memakai definisi buku besar setoran (scope di model
 *    Transaction), yang memang mengecualikan 'Cancelled'. Beda perlakuan ini
 *    disengaja: yang satu "berapa yang dihasilkan", yang lain "berapa uang
 *    fisik yang masih di luar".
 *  - POSISI KAS TIDAK difilter tanggal. Sifatnya saldo saat ini, bukan
 *    peristiwa dalam periode.
 */
class RevenueReport
{
    /** Batas baris tabel kontribusi, agar payload tidak membengkak. */
    private const LIMIT_KONTRIBUTOR = 20;

    public function __construct(private CommissionCalculator $commission)
    {
    }

    public function build(Carbon $start, Carbon $end): array
    {
        $start = (clone $start)->startOfDay();
        $end   = (clone $end)->endOfDay();

        // Periode pembanding: sama panjang, tepat sebelum periode ini.
        // Selisih dihitung dari tanggal saja — memakai $end yang sudah
        // endOfDay() menghasilkan pecahan (30,99 hari) dan jumlah hari meleset.
        $days      = (int) $start->copy()->startOfDay()
            ->diffInDays($end->copy()->startOfDay()) + 1;
        $prevEnd   = (clone $start)->subDay()->endOfDay();
        $prevStart = (clone $prevEnd)->subDays($days - 1)->startOfDay();

        $gross = (float) $this->inRange($start, $end)->sum('amount');
        $count = (int) $this->inRange($start, $end)->count();
        $prev  = (float) $this->inRange($prevStart, $prevEnd)->sum('amount');

        $commission = $this->commissionSum($start, $end);

        return [
            'range' => [
                'from'  => $start->toDateString(),
                'to'    => $end->toDateString(),
                'days'  => $days,
                'label' => $this->labelRentang($start, $end),
            ],
            'summary' => [
                'gross'       => round($gross),
                'trx_count'   => $count,
                'avg_per_trx' => $count > 0 ? round($gross / $count) : 0,
                'commission'  => round($commission),
                'net_driver'  => round($gross - $commission),
                'prev_gross'  => round($prev),
                // null (bukan 0) saat pembanding kosong: "naik 100%" dari nol
                // adalah pernyataan yang menyesatkan.
                'change_pct'  => $prev > 0 ? round((($gross - $prev) / $prev) * 100, 1) : null,
            ],
            'by_method'     => $this->perMetode($start, $end, $gross),
            'daily'         => $this->harian($start, $end),
            'by_zone'       => $this->perZona($start, $end),
            'by_cso'        => $this->perPeran($start, $end, 'cso_id'),
            'by_driver'     => $this->perPeran($start, $end, 'driver_id'),
            'cash_position' => $this->posisiKas(),
            'commission_rate' => $this->commission->rate(),
            'manual_fee_flat' => $this->commission->flat(),
        ];
    }

    private function inRange(Carbon $start, Carbon $end)
    {
        return Transaction::whereBetween('created_at', [$start, $end]);
    }

    /**
     * Potongan dijumlah di SQL lewat CommissionCalculator, BUKAN gross * rate.
     * Order manual (tanpa zona) dikenai tarif flat, jadi perkalian persentase
     * atas total akan meleset setiap kali ada order manual.
     */
    private function commissionSum(Carbon $start, Carbon $end): float
    {
        // selectRaw + value('potongan'), bukan value(DB::raw(...)): yang kedua
        // memperlakukan ekspresi sebagai nama kolom dan diam-diam mengembalikan
        // null, sehingga potongan tampil nol.
        return (float) Transaction::query()
            ->join('bookings', 'bookings.id', '=', 'transactions.booking_id')
            ->whereBetween('transactions.created_at', [$start, $end])
            ->selectRaw($this->commission->sqlSumExpression() . ' as potongan')
            ->value('potongan');
    }

    private function perMetode(Carbon $start, Carbon $end, float $gross): array
    {
        $label = [
            'CashCSO'    => 'Tunai (Kasir)',
            'CashDriver' => 'Tunai (Supir)',
            'QRIS'       => 'QRIS',
        ];

        $rows = $this->inRange($start, $end)
            ->selectRaw('method, COUNT(*) as trx, SUM(amount) as total')
            ->groupBy('method')
            ->get()
            ->keyBy('method');

        // Ketiga metode selalu ditampilkan walau nol, supaya kartunya tidak
        // hilang-muncul dan tata letaknya stabil.
        return collect(array_keys($label))->map(function ($m) use ($rows, $label, $gross) {
            $r     = $rows->get($m);
            $total = (float) ($r->total ?? 0);
            $trx   = (int) ($r->trx ?? 0);

            return [
                'method' => $m,
                'label'  => $label[$m],
                'count'  => $trx,
                'total'  => round($total),
                'avg'    => $trx > 0 ? round($total / $trx) : 0,
                'pct'    => $gross > 0 ? round(($total / $gross) * 100, 1) : 0,
            ];
        })->values()->all();
    }

    /**
     * Tren harian. Hari tanpa transaksi tetap muncul bernilai nol — kalau
     * dilewati, grafiknya memampatkan jeda dan tren terlihat lebih mulus
     * daripada kenyataannya.
     */
    private function harian(Carbon $start, Carbon $end): array
    {
        // DATE() tersedia di SQLite maupun MySQL, jadi tidak perlu percabangan
        // driver seperti helper getSqlDate() untuk format minggu/bulan.
        $rows = $this->inRange($start, $end)
            ->selectRaw('DATE(created_at) as tanggal, SUM(amount) as total, COUNT(*) as trx')
            ->groupBy('tanggal')
            ->get()
            ->keyBy('tanggal');

        $hasil = [];
        for ($d = (clone $start); $d->lte($end); $d->addDay()) {
            $key = $d->toDateString();
            $r   = $rows->get($key);
            $hasil[] = [
                'date'  => $key,
                'total' => round((float) ($r->total ?? 0)),
                'count' => (int) ($r->trx ?? 0),
            ];
        }

        return $hasil;
    }

    private function perZona(Carbon $start, Carbon $end): array
    {
        return Transaction::query()
            ->join('bookings', 'bookings.id', '=', 'transactions.booking_id')
            ->leftJoin('zones', 'zones.id', '=', 'bookings.zone_id')
            ->whereBetween('transactions.created_at', [$start, $end])
            ->selectRaw('bookings.zone_id, zones.name as zona, COUNT(*) as trx, SUM(transactions.amount) as total')
            ->groupBy('bookings.zone_id', 'zones.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'zone_id' => $r->zone_id,
                // zone_id NULL = order manual tanpa zona; inilah yang dikenai
                // potongan flat, jadi barisnya perlu terlihat jelas.
                'zone'    => $r->zona ?? 'Order Manual (tanpa zona)',
                'count'   => (int) $r->trx,
                'total'   => round((float) $r->total),
                'avg'     => $r->trx > 0 ? round((float) $r->total / (int) $r->trx) : 0,
            ])
            ->all();
    }

    /** Kontribusi per CSO ($kolom='cso_id') atau per supir ($kolom='driver_id'). */
    private function perPeran(Carbon $start, Carbon $end, string $kolom): array
    {
        return Transaction::query()
            ->join('bookings', 'bookings.id', '=', 'transactions.booking_id')
            ->join('users', 'users.id', '=', "bookings.{$kolom}")
            ->whereBetween('transactions.created_at', [$start, $end])
            ->selectRaw("bookings.{$kolom} as user_id, users.name, COUNT(*) as trx, SUM(transactions.amount) as total")
            ->groupBy("bookings.{$kolom}", 'users.name')
            ->orderByDesc('total')
            ->limit(self::LIMIT_KONTRIBUTOR)
            ->get()
            ->map(fn ($r) => [
                'id'    => (int) $r->user_id,
                'name'  => $r->name,
                'count' => (int) $r->trx,
                'total' => round((float) $r->total),
            ])
            ->all();
    }

    /**
     * Uang tunai yang masih berada di luar kas koperasi. Memakai scope model
     * yang sama persis dengan yang dipakai aplikasi CSO & supir.
     */
    private function posisiKas(): array
    {
        $csoBelum   = (float) Transaction::cashCsoOutstanding()->sum('amount');
        $csoProses  = (float) Transaction::cashCsoProcessing()->sum('amount');
        $supirBelum = (float) Transaction::cashDriverOutstanding()->sum('amount');

        return [
            'cso_unsettled'      => round($csoBelum),
            'cso_processing'     => round($csoProses),
            'driver_outstanding' => round($supirBelum),
            'total_outside'      => round($csoBelum + $csoProses + $supirBelum),
        ];
    }

    private function labelRentang(Carbon $start, Carbon $end): string
    {
        if ($start->isSameDay($end)) {
            return $start->translatedFormat('d M Y');
        }

        return $start->translatedFormat('d M Y') . ' – ' . $end->translatedFormat('d M Y');
    }
}
