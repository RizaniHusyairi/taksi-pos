<?php

namespace App\Services;

use App\Models\ConcessionPayment;
use App\Models\DriverConcession;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Konsesi bulanan supir — SATU-SATUNYA tempat aturan konsesi diputuskan.
 *
 * Aturan (keputusan koperasi 2026-10-24):
 *  - Semua supir aktif ditagih tiap bulan sebesar Setting::concessionFee().
 *  - Boleh dicicil, nominalnya dipilih supir sendiri dari saldo dompet.
 *  - Supir DIBLOKIR dari antrian hanya bila cicilan bulan berjalan masih Rp 0.
 *    Begitu ada cicilan berapa pun, ia boleh antre lagi.
 */
class ConcessionService
{
    public static function periodNow(): string
    {
        return now()->format('Y-m');
    }

    /** "Oktober 2026" dari "2026-10". */
    public static function periodLabel(string $period): string
    {
        return Carbon::createFromFormat('Y-m-d', $period . '-01')
            ->locale('id')->translatedFormat('F Y');
    }

    /**
     * Terbitkan tagihan satu supir untuk satu periode (idempoten).
     * Mengembalikan null bila fitur mati atau supir tidak aktif.
     */
    public function issue(User $driver, ?string $period = null): ?DriverConcession
    {
        $fee = Setting::concessionFee();

        if ($fee <= 0 || $driver->role !== 'driver' || !$driver->active) {
            return null;
        }

        return DriverConcession::firstOrCreate(
            ['driver_id' => $driver->id, 'period' => $period ?? self::periodNow()],
            ['amount' => $fee, 'status' => 'Open']
        );
    }

    /**
     * Tagihan bulan berjalan. Diterbitkan saat itu juga bila belum ada —
     * jaring pengaman untuk supir baru di tengah bulan atau scheduler yang
     * sempat mati, supaya tidak ada supir yang lolos hanya karena cron gagal.
     */
    public function current(User $driver): ?DriverConcession
    {
        return $this->issue($driver);
    }

    public function paidAmount(DriverConcession $c): int
    {
        return (int) ConcessionPayment::where('concession_id', $c->id)->sum('amount');
    }

    /** Ringkasan untuk dompet supir & gerbang antrian. */
    public function summary(User $driver): ?array
    {
        $c = $this->current($driver);

        if (!$c) {
            return null;
        }

        $paid = $this->paidAmount($c);

        return [
            'id'           => $c->id,
            'period'       => $c->period,
            'period_label' => self::periodLabel($c->period),
            'amount'       => $c->amount,
            'paid'         => $paid,
            'remaining'    => $c->status === 'Open' ? max(0, $c->amount - $paid) : 0,
            'status'       => $c->status,
            'blocked'      => $this->isBlocked($c, $paid),
        ];
    }

    public function isBlocked(DriverConcession $c, ?int $paid = null): bool
    {
        return $c->status === 'Open' && ($paid ?? $this->paidAmount($c)) <= 0;
    }

    /**
     * Pesan penolakan antrian, atau null bila boleh masuk.
     */
    public function gate(User $driver): ?string
    {
        $c = $this->current($driver);

        if (!$c || !$this->isBlocked($c)) {
            return null;
        }

        return 'Konsesi bulan ' . self::periodLabel($c->period)
            . ' belum dibayar sama sekali. Bayar minimal sebagian lewat menu Dompet.';
    }

    /**
     * Alihkan fee flat satu trip mandiri ke konsesi bulan berjalan.
     *
     * Hanya sebesar sisa konsesi: kalau sisanya kurang dari fee, kelebihannya
     * tetap menjadi biaya admin. Konsesi yang sudah lunas/dibebaskan, atau
     * fitur konsesi yang mati, berarti fee utuh tetap biaya admin seperti dulu.
     */
    public function payFromSelfTrip(User $driver, Transaction $trip, int $fee): ?ConcessionPayment
    {
        $c = $this->current($driver);

        if (!$c || $c->status !== 'Open' || $fee <= 0) {
            return null;
        }

        $nominal = min($fee, max(0, $c->amount - $this->paidAmount($c)));
        if ($nominal <= 0) {
            return null;
        }

        $hasil = $this->pay($c, $nominal, 'self_trip', note: 'Trip mandiri #' . $trip->booking_id, transactionId: $trip->id);

        return $hasil instanceof ConcessionPayment ? $hasil : null;
    }

    /**
     * Bagian fee flat trip mandiri yang sudah dialihkan ke konsesi, per
     * transaksi. Dipakai laporan/rincian supaya fee itu tidak terbaca dua kali
     * (sekali sebagai pendapatan admin, sekali sebagai konsesi).
     */
    public static function bagianKonsesi(Transaction $t): int
    {
        return (int) ConcessionPayment::where('transaction_id', $t->id)->value('amount');
    }

    /**
     * Catat satu cicilan.
     *
     * @param  callable|null  $saldoTersedia  Untuk source=wallet: dipanggil
     *         SETELAH baris konsesi terkunci, supaya dua permintaan bersamaan
     *         tidak bisa memotong saldo yang sama dua kali.
     * @return ConcessionPayment|string  cicilan, atau pesan galat
     */
    public function pay(
        DriverConcession $concession,
        int $amount,
        string $source,
        ?callable $saldoTersedia = null,
        ?int $recordedBy = null,
        ?string $note = null,
        ?int $transactionId = null
    ): ConcessionPayment|string {
        return DB::transaction(function () use ($concession, $amount, $source, $saldoTersedia, $recordedBy, $note, $transactionId) {
            $c = DriverConcession::whereKey($concession->id)->lockForUpdate()->first();

            if ($c->status !== 'Open') {
                return $c->status === 'Paid'
                    ? 'Konsesi periode ini sudah lunas.'
                    : 'Konsesi periode ini sudah dibebaskan admin.';
            }

            $sisa = max(0, $c->amount - $this->paidAmount($c));

            if ($amount < 1) {
                return 'Nominal pembayaran minimal Rp 1.';
            }
            if ($amount > $sisa) {
                return 'Nominal melebihi sisa konsesi (Rp ' . number_format($sisa, 0, ',', '.') . ').';
            }

            if ($source === 'wallet') {
                $saldo = (int) floor($saldoTersedia ? $saldoTersedia() : 0);
                if ($amount > $saldo) {
                    return $saldo > 0
                        ? 'Saldo dompet tidak cukup (tersedia Rp ' . number_format($saldo, 0, ',', '.') . ').'
                        : 'Saldo dompet kosong. Bayar tunai ke admin untuk konsesi ini.';
                }
            }

            $payment = ConcessionPayment::create([
                'concession_id' => $c->id,
                'driver_id'     => $c->driver_id,
                'amount'        => $amount,
                'source'        => $source,
                'recorded_by'   => $recordedBy,
                'note'          => $note,
                'transaction_id' => $transactionId,
            ]);

            if ($amount >= $sisa) {
                $c->update(['status' => 'Paid']);
            }

            return $payment;
        });
    }
}
