<?php

namespace App\Jobs;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kirim pesan WhatsApp via WhatsApp Gateway (wg.aptpairport.id) secara async + retry.
 *
 * Format gateway:
 *   POST {wa_endpoint}   header: X-API-Key: <key>
 *   body: { deviceId, to, body }
 *
 * `$token` = API Key gateway (setting `wa_token`). Endpoint & deviceId dibaca dari
 * setting (`wa_endpoint`, `wa_device_id`) dengan default sesuai dokumentasi gateway.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public ?string $target,
        public string $message,
        public ?string $token,
    ) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        if (!$this->target || !$this->token) {
            return;
        }

        $endpoint = Setting::getValue('wa_endpoint')
            ?: 'https://wg.aptpairport.id/api/v1/messages/send';
        $deviceId = (int) (Setting::getValue('wa_device_id') ?: 1);

        $response = Http::withHeaders(['X-API-Key' => $this->token])
            ->acceptJson()
            ->post($endpoint, [
                'deviceId' => $deviceId,
                'to'       => $this->target,
                'body'     => $this->message,
            ]);

        if ($response->failed()) {
            // 5xx / transient → lempar agar di-retry. 4xx (key/nomor invalid) → catat.
            if ($response->serverError()) {
                throw new \RuntimeException("WA Gateway 5xx: {$response->status()}");
            }
            Log::warning("WA Gateway gagal (tidak di-retry): {$response->status()} {$response->body()}");
        }
    }
}
