<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Setting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Memo per-request: semua setting di-load 1x lalu dibaca dari memori.
     * (Sengaja BUKAN Cache facade — driver cache di sini 'database', jadi cache
     * justru = query DB. Memo statis lebih murah & otomatis reset tiap request.)
     */
    protected static ?\Illuminate\Support\Collection $memo = null;

    public static function getValue(string $key, $default = null)
    {
        if (static::$memo === null) {
            static::$memo = static::pluck('value', 'key');
        }
        return static::$memo->get($key, $default);
    }

    public static function forgetMemo(): void
    {
        static::$memo = null;
    }

    /**
     * Radius area bandara (km) yang bisa diatur admin lewat Pengaturan.
     * Fallback ke nilai config bila belum diatur / di-set tidak valid.
     */
    public static function airportRadiusKm(): float
    {
        $v = (float) static::getValue('airport_radius_km');
        return $v > 0 ? $v : (float) config('taksi.driver_queue.radius_km');
    }

    /**
     * Jam operasi pelacakan lokasi (format 'HH:MM'). Nilai efektif =
     * setting admin, fallback ke config. Dipakai app driver untuk
     * mematikan layanan lokasi di luar jam kerja (hemat baterai + privasi).
     *
     * @return array{start: string, end: string}
     */
    public static function operatingHours(): array
    {
        $start = trim((string) static::getValue('operating_start', ''));
        $end   = trim((string) static::getValue('operating_end', ''));
        return [
            'start' => $start !== '' ? $start : (string) config('taksi.operating.start'),
            'end'   => $end   !== '' ? $end   : (string) config('taksi.operating.end'),
        ];
    }

    /**
     * Apakah SEKARANG (WITA) berada di dalam jam operasi?
     * - Jam belum diatur / format tidak valid → true (fail-open: jangan
     *   pernah mengunci driver akibat konfigurasi kosong/salah).
     * - start == end → 24 jam nonstop.
     * - Mendukung jendela lewat tengah malam (mis. 22:00–06:00).
     */
    public static function isWithinOperatingHours(?\Carbon\Carbon $now = null): bool
    {
        $h = static::operatingHours();

        $toMin = static function (string $hhmm): ?int {
            if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
                return null;
            }
            $min = ((int) $m[1]) * 60 + (int) $m[2];
            return ($min >= 0 && $min <= 1439) ? $min : null;
        };

        $startMin = $toMin($h['start']);
        $endMin   = $toMin($h['end']);
        if ($startMin === null || $endMin === null || $startMin === $endMin) {
            return true; // kosong/aneh/sama → selalu aktif (24 jam)
        }

        $tz  = config('taksi.operating.timezone', 'Asia/Makassar');
        $now = $now ? $now->copy()->setTimezone($tz) : \Carbon\Carbon::now($tz);
        $nowMin = ((int) $now->format('H')) * 60 + (int) $now->format('i');

        return $startMin < $endMin
            ? ($nowMin >= $startMin && $nowMin < $endMin)   // jendela normal (siang)
            : ($nowMin >= $startMin || $nowMin < $endMin);  // jendela lewat tengah malam
    }
}
