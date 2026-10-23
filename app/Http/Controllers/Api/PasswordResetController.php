<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppMessage;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * "Lupa Password" aplikasi supir & CSO lewat OTP WhatsApp.
 *
 * Dua langkah: minta kode → kirim kode + password baru. Nomor tujuan SELALU
 * diambil dari phone_number akun, tidak pernah dari aplikasi, sehingga kode
 * tidak bisa dibelokkan ke nomor lain.
 *
 * Admin sengaja tidak dilayani di sini: akunnya terlalu berharga untuk bisa
 * diambil alih cukup dengan memegang satu nomor WA.
 */
class PasswordResetController extends Controller
{
    /** Masa berlaku kode. */
    public const TTL_MINUTES = 5;

    /** Salah tebak sebanyak ini → kode hangus, harus minta yang baru. */
    public const MAX_ATTEMPTS = 5;

    /** Batas kode per akun per jam — juga menahan biaya pesan WA. */
    public const MAX_PER_HOUR = 3;

    /** Jeda minimal antar permintaan kode untuk akun yang sama. */
    public const COOLDOWN_SECONDS = 60;

    /**
     * Jawaban SELALU sama — terdaftar atau tidak, kena batas atau tidak —
     * supaya endpoint ini tidak bisa dipakai menebak username/nomor yang ada.
     * Pesannya dikirim lewat antrean, jadi waktu responsnya pun tidak berbeda.
     */
    public function requestCode(Request $request)
    {
        $validated = $request->validate([
            'login' => 'required|string|max:100',
        ]);

        $user = $this->findUser($validated['login']);

        if ($user && $this->bolehKirim($user)) {
            $kode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // Kode lama dimatikan: hanya kode terakhir yang berlaku.
            PasswordResetOtp::where('user_id', $user->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            PasswordResetOtp::create([
                'user_id'    => $user->id,
                'code_hash'  => Hash::make($kode),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'ip'         => $request->ip(),
            ]);

            SendWhatsAppMessage::dispatch(
                $user->phone_number,
                "*{$kode}* adalah kode reset password Taksi POS Anda.\n\n"
                . 'Berlaku ' . self::TTL_MINUTES . " menit. JANGAN berikan kode ini kepada siapa pun, "
                . "termasuk yang mengaku petugas.\n\n"
                . 'Bukan Anda yang meminta? Abaikan pesan ini — password Anda tidak berubah.'
            );
        }

        return response()->json([
            'message' => 'Jika akun terdaftar, kode verifikasi telah dikirim ke WhatsApp yang terdaftar.',
            'expires_in' => self::TTL_MINUTES * 60,
        ]);
    }

    public function reset(Request $request)
    {
        $validated = $request->validate([
            'login'    => 'required|string|max:100',
            'code'     => 'required|digits:6',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $gagal = response()->json([
            'message' => 'Kode salah atau sudah kedaluwarsa. Minta kode baru bila perlu.',
        ], 422);

        $user = $this->findUser($validated['login']);
        if (!$user) {
            return $gagal;
        }

        $berhasil = DB::transaction(function () use ($user, $validated) {
            $otp = PasswordResetOtp::where('user_id', $user->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (!$otp || $otp->attempts >= self::MAX_ATTEMPTS) {
                return false;
            }

            if (!Hash::check($validated['code'], $otp->code_hash)) {
                $otp->increment('attempts');
                return false;
            }

            $otp->update(['used_at' => now()]);
            $user->update(['password' => Hash::make($validated['password'])]);

            // Semua perangkat yang masih login dikeluarkan: kalau password
            // direset karena dicuri, sesi si pencuri ikut putus.
            $user->tokens()->delete();

            return true;
        });

        if (!$berhasil) {
            return $gagal;
        }

        Log::info('Password direset lewat OTP WhatsApp', [
            'user_id' => $user->id,
            'ip'      => $request->ip(),
        ]);

        SendWhatsAppMessage::dispatch(
            $user->phone_number,
            'Password akun Taksi POS Anda (' . $user->username . ') baru saja diganti. '
            . 'Semua perangkat telah dikeluarkan. Bila ini BUKAN Anda, segera hubungi admin.'
        );

        return response()->json([
            'message' => 'Password berhasil diganti. Silakan login dengan password baru.',
        ]);
    }

    /**
     * Username persis, atau nomor HP dalam bentuk apa pun yang lazim diketik
     * (0812…, 62812…, +62 812-…). Hanya supir/CSO aktif yang punya nomor.
     */
    protected function findUser(string $login): ?User
    {
        $login = trim($login);
        $digit = preg_replace('/\D/', '', $login);

        $nomor = [];
        if (strlen($digit) >= 8) {
            $lokal = str_starts_with($digit, '62') ? substr($digit, 2)
                : (str_starts_with($digit, '0') ? substr($digit, 1) : $digit);
            $nomor = ['0' . $lokal, '62' . $lokal, '+62' . $lokal, $lokal];
        }

        $cocok = User::whereIn('role', ['driver', 'cso'])
            ->where('active', true)
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->where(function ($q) use ($login, $nomor) {
                $q->where('username', $login);
                if ($nomor) {
                    $q->orWhereIn('phone_number', $nomor);
                }
            })
            ->limit(2)
            ->get();

        // Satu nomor dipakai dua akun → jangan menebak akun mana yang
        // dimaksud. Pengguna diarahkan memakai username.
        if ($cocok->count() !== 1) {
            return $cocok->firstWhere('username', $login);
        }

        return $cocok->first();
    }

    protected function bolehKirim(User $user): bool
    {
        $terakhir = PasswordResetOtp::where('user_id', $user->id)->latest('id')->first();
        if ($terakhir && $terakhir->created_at->gt(now()->subSeconds(self::COOLDOWN_SECONDS))) {
            return false;
        }

        return PasswordResetOtp::where('user_id', $user->id)
            ->where('created_at', '>', now()->subHour())
            ->count() < self::MAX_PER_HOUR;
    }
}
