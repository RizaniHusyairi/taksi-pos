<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\FcmService;

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

        // Pemuatan kredensial & bentuk payload dipusatkan di FcmService supaya
        // tombol "Tes Push" di panel admin menempuh jalur yang persis sama
        // dengan notifikasi order sungguhan.
        $hasil = FcmService::send($this->fcmToken, $this->title, $this->body, $this->data);

        if ($hasil['ok']) {
            return;
        }

        // 5xx / gangguan sesaat -> lempar agar di-retry.
        // 4xx (token perangkat mati) & konfigurasi belum siap -> percuma
        // diulang; FcmService sudah mencatatnya ke log.
        if ($hasil['status'] !== null && $hasil['status'] >= 500) {
            throw new \RuntimeException("FCM 5xx: {$hasil['status']}");
        }
    }
}
