<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Transaction;

/**
 * Satu-satunya sumber kebenaran perhitungan potongan (komisi) koperasi.
 *
 * Aturannya TIDAK seragam: order berzona dipotong PERSENTASE dari tarif,
 * sedangkan order manual (tanpa zona) dikenai TARIF FLAT. Sebelum kelas ini
 * ada, aturan itu hanya hidup di DriverApiController::feeTerutang(), sementara
 * Laporan Pendapatan admin memakai `total * rate` untuk semua transaksi —
 * sehingga setiap order manual membuat laporan dan tagihan setoran supir
 * berbeda angka.
 *
 * Dua bentuk pemakaian:
 *   - forTransaction()    : per baris, dipakai saat menagih supir;
 *   - sqlSumExpression()  : agregat di SQL, dipakai laporan supaya tidak perlu
 *                           menarik ribuan baris ke PHP hanya untuk dijumlah.
 * Keduanya WAJIB menghasilkan angka yang sama untuk data yang sama.
 */
class CommissionCalculator
{
    /**
     * Porsi komisi untuk order berzona, dalam pecahan (0.2 = 20%).
     *
     * `?: 0.2` dipertahankan persis seperti pemakaian lama: rate yang kosong
     * ATAU bernilai 0 sama-sama jatuh ke bawaan 20%.
     */
    public function rate(): float
    {
        return (float) Setting::getValue('commission_rate') ?: 0.2;
    }

    /** Potongan tetap untuk order manual (tanpa zona), dalam rupiah. */
    public function flat(): int
    {
        return (int) (Setting::getValue('manual_fee_flat') ?: 10000);
    }

    /** Potongan untuk satu transaksi, memakai setelan yang berlaku. */
    public function forTransaction(Transaction $t): float
    {
        return self::compute($t, $this->rate(), $this->flat());
    }

    /**
     * RUMUSNYA, satu-satunya salinan. Statis dan menerima rate/flat eksplisit
     * supaya pemanggil yang membaca Setting sekali lalu mengulang ribuan baris
     * (penagihan setoran supir) tidak perlu menyentuh container di dalam loop.
     *
     * Booking yang tidak termuat relasinya dianggap berzona (memakai
     * persentase) — sama seperti perilaku sebelumnya, karena syaratnya
     * `zone_id === null` tidak terpenuhi saat booking-nya null.
     */
    public static function compute(Transaction $t, float $rate, int $flat): float
    {
        return $t->booking && $t->booking->zone_id === null
            ? (float) $flat
            : (float) $t->amount * $rate;
    }

    /**
     * Ekspresi SQL penjumlah potongan, untuk query yang sudah join `bookings`.
     *
     * Nilainya di-cast di PHP sebelum disisipkan, jadi tidak ada input pengguna
     * yang masuk ke SQL di sini.
     */
    public function sqlSumExpression(
        string $zoneColumn = 'bookings.zone_id',
        string $amountColumn = 'transactions.amount'
    ): string {
        $rate = $this->rate();
        $flat = $this->flat();

        return "SUM(CASE WHEN {$zoneColumn} IS NULL THEN {$flat} ELSE {$amountColumn} * {$rate} END)";
    }
}
