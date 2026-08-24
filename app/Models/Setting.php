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
     * Batas utang (Rp) sebelum supir dilarang masuk antrian.
     *
     * Tanpa batas ini, penyelesaian utang HANYA terjadi saat pencairan dana —
     * sehingga supir yang saldonya negatif cukup tidak pernah menekan
     * "Cairkan", dan uang koperasi yang ia pegang tidak punya jatuh tempo sama
     * sekali. Lihat DriverApiController::hitungSaldo().
     *
     * Mengikuti pola [outOfAreaGraceMinutes], BUKAN [airportRadiusKm]: 0 adalah
     * nilai yang SAH dan berarti "pembatasan dimatikan" — supir tetap bisa
     * masuk antrian berapa pun utangnya. Itu penting supaya koperasi bisa
     * mengaktifkan fitur ini secara bertahap.
     */
    public static function maxDriverDebt(): int
    {
        $v = static::getValue('max_driver_debt');

        if ($v === null || $v === '' || !is_numeric($v)) {
            return 0; // belum diatur = pembatasan mati
        }

        return max(0, (int) $v);
    }

    /**
     * Tenggang (menit) supir standby yang berada di luar area bandara sebelum
     * antriannya hangus otomatis. Bisa diatur admin lewat Pengaturan.
     *
     * CATATAN: sengaja TIDAK memakai pola `$v > 0 ? ...` seperti [airportRadiusKm] —
     * di sini 0 adalah nilai yang SAH dan berarti "auto-keluar dinonaktifkan",
     * bukan "belum diatur". Jadi yang dicek adalah "ada isinya & berupa angka",
     * lalu hasilnya dijepit ke rentang yang sama dengan validasi form admin.
     */
    public static function outOfAreaGraceMinutes(): int
    {
        $raw = static::getValue('out_of_area_grace_minutes');

        if ($raw !== null && $raw !== '' && is_numeric($raw)) {
            return max(0, min(720, (int) $raw));
        }

        return max(0, min(720, (int) config('taksi.driver_queue.out_of_area_grace_minutes')));
    }

    /** Tenggang di luar area dalam detik. 0 = auto-keluar dinonaktifkan. */
    public static function outOfAreaGraceSeconds(): int
    {
        return static::outOfAreaGraceMinutes() * 60;
    }

    /**
     * Titik pusat area bandara (lintang) yang bisa diatur admin lewat
     * Pengaturan. Fallback ke config bila belum diatur / tidak valid.
     *
     * CATATAN: sengaja TIDAK memakai pola `$v > 0 ? ...` seperti radius —
     * lintang/bujur 0 itu koordinat yang sah (khatulistiwa / meridian utama),
     * jadi yang dicek adalah "ada isinya & berupa angka", bukan "lebih dari 0".
     */
    public static function airportLatitude(): float
    {
        return static::coordOrDefault('airport_latitude', 'taksi.driver_queue.latitude', 90);
    }

    /** Titik pusat area bandara (bujur). Lihat [airportLatitude]. */
    public static function airportLongitude(): float
    {
        return static::coordOrDefault('airport_longitude', 'taksi.driver_queue.longitude', 180);
    }

    /**
     * Titik pusat area bandara sebagai pasangan siap pakai.
     *
     * @return array{latitude: float, longitude: float}
     */
    public static function airportCenter(): array
    {
        return [
            'latitude'  => static::airportLatitude(),
            'longitude' => static::airportLongitude(),
        ];
    }

    /**
     * Baca satu koordinat dari setting; tolak nilai kosong/non-numerik/di luar
     * rentang dan jatuh ke config supaya geofence tidak pernah rusak gara-gara
     * baris setting yang cacat.
     */
    protected static function coordOrDefault(string $key, string $configKey, float $max): float
    {
        $raw = static::getValue($key);

        if ($raw !== null && $raw !== '' && is_numeric($raw)) {
            $v = (float) $raw;
            if ($v >= -$max && $v <= $max) {
                return $v;
            }
        }

        return (float) config($configKey);
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
