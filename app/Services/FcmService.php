<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Satu-satunya tempat kredensial FCM dibaca dan dinilai.
 *
 * Sebelum ini, pemuatan kredensial hidup di dalam App\Jobs\SendFcmNotification.
 * Akibatnya kegagalan paling umum — file kredensial belum ada — hanya muncul
 * sebagai satu baris di storage/logs, di tempat yang tidak pernah dilihat
 * admin. Push notification pun mati diam-diam: CSO tetap melihat "order
 * terkirim", supir tidak pernah menerima apa pun, dan tidak ada satu pun layar
 * yang menunjukkan ada yang salah.
 *
 * Kelas ini memisahkan "apakah konfigurasinya siap" dari "kirim pesan", supaya
 * panel admin bisa menanyakan hal pertama tanpa harus mengirim apa pun.
 */
class FcmService
{
    /** Lokasi service account key. Diabaikan git lewat storage/app/.gitignore. */
    public static function credentialsPath(): string
    {
        return storage_path('app/firebase_credentials.json');
    }

    /**
     * Kondisi konfigurasi FCM, aman dipanggil kapan saja (tidak mengirim apa pun).
     *
     * Sengaja mengembalikan ALASAN yang spesifik, bukan sekadar true/false:
     * "file tidak ada", "bukan service account", dan "JSON rusak" menuntut
     * tindakan yang berbeda dari admin.
     *
     * @return array{ready:bool, reason:?string, project_id:?string, client_email:?string}
     */
    public static function status(): array
    {
        $path = static::credentialsPath();

        if (!file_exists($path)) {
            return [
                'ready'        => false,
                'reason'       => 'File kredensial belum ada di storage/app/firebase_credentials.json.',
                'project_id'   => null,
                'client_email' => null,
            ];
        }

        $isi = json_decode((string) file_get_contents($path), true);

        if (!is_array($isi)) {
            return [
                'ready'        => false,
                'reason'       => 'File kredensial bukan JSON yang sah — kemungkinan rusak saat diunduh/disalin.',
                'project_id'   => null,
                'client_email' => null,
            ];
        }

        // Kesalahan yang paling sering terjadi: yang diunduh adalah berkas
        // konfigurasi APLIKASI (google-services.json), bukan SERVICE ACCOUNT
        // KEY. Keduanya sama-sama JSON dari Firebase Console, tapi hanya yang
        // kedua bisa dipakai server untuk mengirim pesan.
        if (($isi['type'] ?? null) !== 'service_account') {
            return [
                'ready'        => false,
                'reason'       => 'Ini bukan Service Account Key (field "type" bukan "service_account"). '
                    . 'Jangan pakai google-services.json — unduh dari Project settings → Service accounts.',
                'project_id'   => $isi['project_id'] ?? null,
                'client_email' => null,
            ];
        }

        foreach (['project_id', 'client_email', 'private_key'] as $wajib) {
            if (empty($isi[$wajib])) {
                return [
                    'ready'        => false,
                    'reason'       => "Service Account Key tidak lengkap: field \"{$wajib}\" kosong.",
                    'project_id'   => $isi['project_id'] ?? null,
                    'client_email' => $isi['client_email'] ?? null,
                ];
            }
        }

        return [
            'ready'        => true,
            'reason'       => null,
            'project_id'   => $isi['project_id'],
            // Ditampilkan ke admin sebagai penanda project mana yang terpasang.
            // Bukan rahasia: ini alamat robot, bukan kunci privatnya.
            'client_email' => $isi['client_email'],
        ];
    }

    /**
     * Kirim satu pesan ke satu token. Mengembalikan hasil yang bisa dibaca
     * manusia, BUKAN melempar exception — pemanggilnya (job & tombol tes)
     * sama-sama butuh melaporkan kegagalan, bukan meledak.
     *
     * @return array{ok:bool, status:?int, message:string}
     */
    public static function send(string $token, string $title, string $body, array $data = []): array
    {
        $status = static::status();
        if (!$status['ready']) {
            return ['ok' => false, 'status' => null, 'message' => $status['reason']];
        }

        try {
            $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/firebase.messaging'],
                static::credentialsPath()
            );
            $accessToken = $credentials->fetchAuthToken(
                \Google\Auth\HttpHandler\HttpHandlerFactory::build()
            )['access_token'];
        } catch (\Throwable $e) {
            // Jam server meleset jauh juga mendarat di sini (JWT ditolak) —
            // penyebab yang sangat membingungkan kalau pesannya disembunyikan.
            Log::error('FCM: gagal menukar kredensial jadi access token: ' . $e->getMessage());
            return [
                'ok'      => false,
                'status'  => null,
                'message' => 'Kredensial ditolak Google: ' . $e->getMessage(),
            ];
        }

        // Semua nilai `data` FCM WAJIB string; angka/bool akan ditolak server.
        $dataString = array_map(fn ($v) => (string) $v, $data);

        // Bentuk payload SENGAJA identik dengan yang dipakai order sungguhan.
        // Kalau tombol tes memakai payload yang lebih sederhana, ia bisa
        // berhasil sementara notifikasi asli gagal — persis jenis "tes hijau
        // tapi produksi mati" yang membuat orang berhenti percaya pada tesnya.
        // channel_id wajib cocok dengan channel di notification_service.dart.
        $response = Http::withToken($accessToken)->post(
            "https://fcm.googleapis.com/v1/projects/{$status['project_id']}/messages:send",
            [
                'message' => [
                    'token'        => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data'         => array_merge(
                        ['click_action' => 'FLUTTER_NOTIFICATION_CLICK'],
                        $dataString
                    ),
                    'android' => [
                        'priority'     => 'HIGH',
                        'notification' => [
                            'channel_id'              => 'high_importance_channel',
                            'default_sound'           => true,
                            'default_vibrate_timings' => true,
                        ],
                    ],
                ],
            ]
        );

        if ($response->successful()) {
            return ['ok' => true, 'status' => $response->status(), 'message' => 'Pesan diterima FCM.'];
        }

        $pesan = $response->json('error.message') ?? $response->body();

        // Token perangkat kedaluwarsa adalah kondisi NORMAL (aplikasi dicopot,
        // data dibersihkan), bukan salah konfigurasi. Dibedakan supaya admin
        // tidak mengira kredensialnya rusak.
        if (in_array($response->status(), [400, 404], true)
            && str_contains(strtolower((string) $pesan), 'not found')) {
            $pesan = 'Token perangkat sudah tidak berlaku (aplikasi dicopot / data dibersihkan). '
                . 'Minta supir membuka aplikasi lagi agar tokennya diperbarui.';
        }

        // "SenderId mismatch" apa adanya nyaris mustahil ditebak maksudnya,
        // padahal ini kegagalan yang PALING mudah terjadi: kunci server dan
        // aplikasi supir berasal dari dua project Firebase yang berbeda.
        // Kredensialnya sendiri sah — itulah yang membuatnya membingungkan.
        if ($response->status() === 403
            && str_contains(strtolower((string) $pesan), 'senderid mismatch')) {
            $pesan = 'Project Firebase tidak cocok. Kunci server ini milik project "'
                . $status['project_id'] . '", sedangkan aplikasi supir terdaftar di project lain '
                . '(lihat driver_app/android/app/google-services.json). Keduanya HARUS satu project: '
                . 'unduh Service Account Key dari project yang sama dengan aplikasi, '
                . 'atau ganti google-services.json lalu build ulang aplikasinya.';
        }

        Log::warning("FCM gagal ({$response->status()}): {$pesan}");

        return ['ok' => false, 'status' => $response->status(), 'message' => $pesan];
    }
}
