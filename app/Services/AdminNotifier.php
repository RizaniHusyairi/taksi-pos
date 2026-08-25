<?php

namespace App\Services;

use App\Models\AdminNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Penulis notifikasi untuk ikon lonceng admin.
 *
 * Dua janji yang dipegang kelas ini:
 *
 *  1. MENULIS NOTIFIKASI TIDAK PERNAH MENGGAGALKAN AKSI UTAMA. Setoran atau
 *     pencairan yang sudah tersimpan tidak boleh batal hanya karena baris
 *     notifikasinya gagal ditulis. Karena itu semuanya dibungkus try/catch dan
 *     kegagalannya hanya dicatat ke log — pola yang sama dengan pengiriman
 *     email/WhatsApp yang sudah ada di controller-controller terkait.
 *  2. Tabelnya tidak tumbuh tanpa batas: baris lama dipangkas sesekali.
 */
class AdminNotifier
{
    /** Umur maksimal riwayat notifikasi. */
    private const SIMPAN_HARI = 90;

    /**
     * Peluang pemangkasan dijalankan pada satu penulisan (1 dari N).
     *
     * Dilakukan menumpang penulisan, bukan lewat scheduler, supaya fitur ini
     * tidak menambah satu lagi proses yang harus dipastikan berjalan di server.
     */
    private const PELUANG_PANGKAS = 50;

    public function notify(
        string $type,
        string $title,
        string $body,
        string $link,
        string $level = 'info',
        ?Model $related = null
    ): void {
        try {
            AdminNotification::create([
                'type'         => $type,
                'title'        => $title,
                // Kolomnya 500 karakter; pesan sepanjang itu pun tidak terbaca
                // di panel, jadi dipotong di sini alih-alih meledak di database.
                'body'         => mb_substr($body, 0, 500),
                'link'         => $link,
                'level'        => $level,
                'related_type' => $related ? $this->namaJenis($related) : null,
                'related_id'   => $related?->getKey(),
            ]);

            if (random_int(1, self::PELUANG_PANGKAS) === 1) {
                $this->pangkas();
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal menulis notifikasi admin: ' . $e->getMessage(), [
                'type'  => $type,
                'title' => $title,
            ]);
        }
    }

    /** Nama pendek kelas model, mis. App\Models\CsoDeposit -> cso_deposit. */
    private function namaJenis(Model $model): string
    {
        return \Illuminate\Support\Str::snake(class_basename($model));
    }

    private function pangkas(): void
    {
        AdminNotification::where('created_at', '<', now()->subDays(self::SIMPAN_HARI))->delete();
    }
}
