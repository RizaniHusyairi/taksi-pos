<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Kirim pesan WhatsApp via Fonnte secara async + retry.
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

        $response = Http::withHeaders(['Authorization' => $this->token])
            ->post('https://api.fonnte.com/send', [
                'target' => $this->target,
                'message' => $this->message,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Fonnte gagal: {$response->status()}");
        }
    }
}
