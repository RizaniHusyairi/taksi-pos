<?php

namespace App\Jobs;

use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Kirim pesan WhatsApp via WhatsApp Gateway (wg.aptpairport.id) secara async + retry.
 *
 * Format request/respons dan resolusi URL ditangani [WhatsAppService] agar
 * hanya ada SATU tempat yang tahu bentuk API gateway.
 *
 * `$token` masih diterima demi kompatibilitas pemanggil lama, tapi hanya dipakai
 * sebagai penanda "WA aktif" — kunci sebenarnya dibaca service dari setting,
 * supaya job yang sudah mengantre tidak memakai key basi setelah admin menggantinya.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public ?string $target,
        public string $message,
        public ?string $token = null,
    ) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        if (!$this->target || !WhatsAppService::apiKey()) {
            return;
        }

        $hasil = WhatsAppService::send($this->target, $this->message);

        if ($hasil['ok']) {
            return;
        }

        // 5xx / gangguan jaringan → lempar agar di-retry.
        // 4xx & success:false (key/nomor/scope salah) → percuma diulang, cukup dicatat.
        $status = $hasil['status'];
        if ($status === null || $status >= 500) {
            throw new \RuntimeException('WA Gateway tidak dapat dihubungi: ' . $hasil['message']);
        }

        Log::warning("WA Gateway gagal (tidak di-retry): HTTP {$status} — {$hasil['message']}");
    }
}
