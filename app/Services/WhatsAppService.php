<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Klien WhatsApp Gateway (wg.aptpairport.id), mengikuti dokumentasi resminya:
 *
 *   Base URL : https://wg.aptpairport.id/api/v1
 *   Auth     : header  X-API-Key: wag_<prefix>.<secret>
 *   Kirim    : POST /messages/send   body { deviceId?, to, body }
 *   Riwayat  : GET  /messages        ?direction=&status=&limit=
 *   Respons  : { success, message, data }   ← SELALU amplop ini
 *
 * Semua kredensial dibaca dari tabel settings (`wa_token`, `wa_endpoint`,
 * `wa_device_id`) sehingga bisa diubah admin tanpa deploy ulang.
 */
class WhatsAppService
{
    public const DEFAULT_BASE_URL = 'https://wg.aptpairport.id/api/v1';

    /** API Key gateway. Null bila admin belum mengisinya. */
    public static function apiKey(): ?string
    {
        $key = trim((string) Setting::getValue('wa_token'));
        return $key !== '' ? $key : null;
    }

    /**
     * Base URL gateway, dinormalkan.
     *
     * Setting `wa_endpoint` boleh diisi base URL (`.../api/v1`) MAUPUN URL kirim
     * lengkap (`.../api/v1/messages/send`) — nilai lama memakai bentuk kedua,
     * jadi keduanya tetap diterima agar konfigurasi tersimpan tidak rusak.
     */
    public static function baseUrl(): string
    {
        $raw = trim((string) Setting::getValue('wa_endpoint'));
        $url = rtrim($raw !== '' ? $raw : self::DEFAULT_BASE_URL, '/');

        // Buang sufiks path kirim bila admin menyimpan URL lengkap.
        foreach (['/messages/send', '/messages'] as $sufiks) {
            if (str_ends_with($url, $sufiks)) {
                return substr($url, 0, -strlen($sufiks));
            }
        }

        return $url;
    }

    /** POST /messages/send */
    public static function sendUrl(): string
    {
        return self::baseUrl() . '/messages/send';
    }

    /** GET /messages */
    public static function messagesUrl(): string
    {
        return self::baseUrl() . '/messages';
    }

    /**
     * Device ID yang dipakai, atau null bila memakai device bawaan API Key.
     *
     * Nilai bawaan sistem adalah 0 dan 0 berarti "pakai device default".
     * `deviceId: 0` TIDAK PERNAH dikirim ke gateway — tidak ada device ber-id 0,
     * jadi mengirimnya hanya akan ditolak. Sama halnya dengan nilai kosong.
     * API Key hanya boleh memakai device default-nya; mengirim id yang salah
     * membuat gateway membalas 403.
     */
    public const DEFAULT_DEVICE_ID = 0;

    public static function deviceId(): ?int
    {
        $raw = trim((string) Setting::getValue('wa_device_id', (string) self::DEFAULT_DEVICE_ID));
        if ($raw === '' || (int) $raw <= 0) {
            return null;
        }
        return (int) $raw;
    }

    /** Body request sesuai dokumentasi: { deviceId?, to, body }. */
    public static function payload(string $to, string $body): array
    {
        $payload = ['to' => $to, 'body' => $body];
        $device = self::deviceId();
        if ($device !== null) {
            $payload['deviceId'] = $device;
        }
        return $payload;
    }

    /** Klien HTTP dengan header autentikasi gateway. */
    public static function http(int $timeout = 15): PendingRequest
    {
        return Http::withHeaders(['X-API-Key' => (string) self::apiKey()])
            ->acceptJson()
            ->timeout($timeout);
    }

    /**
     * Kirim satu pesan teks.
     *
     * @return array{ok: bool, status: int|null, queued_id: mixed, queue_status: mixed, message: string, body: mixed}
     *   `ok` bernilai true HANYA bila HTTP sukses DAN amplop `success` tidak
     *   bernilai false — gateway bisa membalas 200 dengan success:false, dan
     *   memperlakukan itu sebagai terkirim akan menyembunyikan kegagalan nyata.
     */
    public static function send(string $to, string $body, int $timeout = 15): array
    {
        if (!self::apiKey()) {
            return self::hasil(false, null, null, 'API Key WA belum diatur.');
        }

        try {
            $res = self::http($timeout)->post(self::sendUrl(), self::payload($to, $body));
        } catch (\Throwable $e) {
            return self::hasil(false, null, null, 'Gagal menghubungi gateway: ' . $e->getMessage());
        }

        $json = $res->json();
        $amplopOk = !(is_array($json) && array_key_exists('success', $json) && $json['success'] === false);
        $ok = $res->successful() && $amplopOk;

        $pesan = is_array($json) && !empty($json['message'])
            ? (string) $json['message']
            : ($ok ? 'Pesan dimasukkan ke antrean' : substr($res->body(), 0, 180));

        return self::hasil($ok, $res->status(), $json, $pesan);
    }

    /** Bentuk hasil seragam untuk pemanggil. */
    protected static function hasil(bool $ok, ?int $status, mixed $json, string $message): array
    {
        $data = is_array($json) && isset($json['data']) && is_array($json['data']) ? $json['data'] : [];

        return [
            'ok'           => $ok,
            'status'       => $status,
            'queued_id'    => $data['id'] ?? null,
            'queue_status' => $data['status'] ?? null,   // mis. "QUEUED"
            'message'      => $message,
            'body'         => $json,
        ];
    }
}
