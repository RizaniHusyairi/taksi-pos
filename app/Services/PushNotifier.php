<?php

namespace App\Services;

use App\Jobs\SendFcmNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Satu-satunya pintu pengiriman push notification (FCM) ke pengguna.
 *
 * Dua janji yang dipegang kelas ini, sama seperti App\Services\AdminNotifier:
 *
 *  1. MENGIRIM PUSH TIDAK PERNAH MENGGAGALKAN AKSI UTAMA. Setoran yang sudah
 *     tersimpan tidak boleh batal hanya karena notifikasinya gagal dikirim.
 *  2. Pengguna tanpa token DILEWATI DIAM-DIAM — itu keadaan normal, bukan
 *     galat: banyak akun belum pernah membuka aplikasi, dan admin memang tidak
 *     memakai aplikasi mobile sama sekali.
 *
 * Pengiriman sesungguhnya tetap lewat queue (SendFcmNotification) supaya
 * permintaan HTTP pengguna tidak menunggu jaringan Google.
 */
class PushNotifier
{
    public function toUser(?User $user, string $title, string $body, array $data = []): void
    {
        if (!$user || empty($user->fcm_token)) {
            return;
        }

        try {
            SendFcmNotification::dispatch($user->fcm_token, $title, $body, $data);
        } catch (\Throwable $e) {
            Log::warning('Gagal mengantre push notification: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'title'   => $title,
            ]);
        }
    }
}
