<?php

namespace App\Services;

use App\Models\DriverQueue;
use Illuminate\Support\Facades\Log;

/**
 * Peringatan pra-giliran untuk supir.
 *
 * Di lapangan supir biasanya keluar mobil dan mengobrol sambil menunggu.
 * Yang membuat penumpang menunggu lama bukan jari supir, tapi jarak supir ke
 * mobilnya. Maka supir diberi tahu SEBELUM order datang: saat naik ke posisi
 * 2 ("bersiap"), lalu posisi 1 ("giliran Anda berikutnya").
 *
 * Aman dipanggil sesering apa pun: tiap entri antrian mengingat posisi
 * terbaik yang sudah diberi tahu (`heads_up_level`), jadi push yang sama
 * tidak pernah terkirim dua kali. Tidak pernah melempar exception — antrian
 * yang berubah tidak boleh gagal hanya karena notifikasinya gagal.
 */
class QueueHeadsUpService
{
    private const WATCHED_POSITIONS = 2;

    public function __construct(private PushNotifier $push)
    {
    }

    public function notify(): void
    {
        try {
            $top = DriverQueue::with('driver')
                ->ready()
                ->limit(self::WATCHED_POSITIONS)
                ->get();

            foreach ($top as $index => $entry) {
                $position = $index + 1;

                // Sudah pernah diberi tahu posisi ini atau yang lebih baik.
                if ($entry->heads_up_level !== null && $entry->heads_up_level <= $position) {
                    continue;
                }

                // Bersyarat di SQL: dua perubahan antrian yang berbarengan
                // tidak boleh sama-sama mengirim push.
                $claimed = DriverQueue::whereKey($entry->id)
                    ->where(function ($q) use ($position) {
                        $q->whereNull('heads_up_level')->orWhere('heads_up_level', '>', $position);
                    })
                    ->update(['heads_up_level' => $position]);

                if ($claimed === 0) {
                    continue;
                }

                [$title, $body] = $position === 1
                    ? ['Giliran Anda Berikutnya! 🚖', 'Kembali ke mobil sekarang — order berikutnya untuk Anda.']
                    : ['Giliran Anda Sebentar Lagi', 'Anda di posisi 2. Bersiap di dekat mobil.'];

                $this->push->toUser($entry->driver, $title, $body, [
                    'type'     => 'queue_heads_up',
                    'position' => (string) $position,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Peringatan pra-giliran gagal: ' . $e->getMessage());
        }
    }
}
