<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kirim push notification FCM (HTTP v1) secara async + retry.
 * Token FCM dilewatkan sebagai string (bukan model) supaya aman diserialisasi.
 */
class SendFcmNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public ?string $fcmToken,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        if (!$this->fcmToken) {
            return;
        }

        $credentialsPath = storage_path('app/firebase_credentials.json');
        if (!file_exists($credentialsPath)) {
            Log::error("FCM: file kredensial tidak ditemukan di {$credentialsPath}");
            return; // tak ada gunanya retry tanpa file kredensial
        }

        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
        $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials($scopes, $credentialsPath);
        $accessToken = $credentials->fetchAuthToken(\Google\Auth\HttpHandler\HttpHandlerFactory::build())['access_token'];
        $projectId = json_decode(file_get_contents($credentialsPath), true)['project_id'];

        $response = Http::withToken($accessToken)->post(
            "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
            [
                'message' => [
                    'token' => $this->fcmToken,
                    'notification' => ['title' => $this->title, 'body' => $this->body],
                    'data' => array_merge(['click_action' => 'FLUTTER_NOTIFICATION_CLICK'], $this->data),
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => [
                            'channel_id' => 'high_importance_channel',
                            'default_sound' => true,
                            'default_vibrate_timings' => true,
                        ],
                    ],
                ],
            ]
        );

        if ($response->failed()) {
            // 5xx / transient -> lempar agar di-retry. 4xx (token mati) -> cukup catat.
            if ($response->serverError()) {
                throw new \RuntimeException("FCM 5xx: {$response->status()}");
            }
            Log::warning("FCM gagal (tidak di-retry): {$response->status()} {$response->body()}");
        }
    }
}
