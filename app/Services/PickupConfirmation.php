<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\DriverActivity;
use Illuminate\Support\Facades\Log;

/**
 * Satu pintu untuk menandai bahwa supir sudah berangkat menjemput — momen
 * yang MENERBITKAN karcis di aplikasi CSO.
 *
 * Tiga jalur memanggil kelas ini, dan semuanya harus berperilaku sama:
 *  - tombol "SAYA JEMPUT" di aplikasi / notifikasi supir,
 *  - tombol "MULAI PERJALANAN" bila supir melewatkan tombol pertama,
 *  - konfirmasi otomatis saat mobil supir sudah bergerak (updateLocation).
 */
class PickupConfirmation
{
    public const SOURCES = ['button', 'notification', 'auto_location'];

    public function __construct(private PushNotifier $push)
    {
    }

    /**
     * Tandai order sebagai "supir menuju penumpang".
     *
     * Mengembalikan true hanya bila panggilan INI yang mengonfirmasi. Update
     * dibuat bersyarat (`whereNull`) di level SQL supaya tombol notifikasi dan
     * ping lokasi yang tiba bersamaan tidak mengirim push "Karcis Siap" dua kali.
     */
    public function confirm(Booking $booking, string $source, $lat = null, $lng = null): bool
    {
        if (!in_array($source, self::SOURCES, true)) {
            $source = 'button';
        }

        $now = now();
        $updated = Booking::whereKey($booking->id)
            ->whereNull('pickup_confirmed_at')
            ->whereIn('status', ['Assigned', 'OnTrip'])
            ->update([
                'pickup_confirmed_at' => $now,
                'pickup_lat'          => $lat,
                'pickup_lng'          => $lng,
                'pickup_source'       => $source,
                'updated_at'          => $now,
            ]);

        if ($updated === 0) {
            return false;
        }

        $booking->refresh()->loadMissing(['driver.driverProfile', 'zoneTo', 'cso']);

        $label = match ($source) {
            'auto_location' => 'otomatis — mobil sudah bergerak',
            'notification'  => 'dari notifikasi',
            default         => 'tombol aplikasi',
        };
        try {
            DriverActivity::create([
                'user_id'       => $booking->driver_id,
                'activity_type' => 'PICKUP_CONFIRMED',
                'description'   => "Berangkat menjemput penumpang ({$label})",
            ]);
        } catch (\Throwable $e) {
            Log::error('Log aktivitas jemput gagal: ' . $e->getMessage());
        }

        $driver = $booking->driver;
        $line = $driver?->driverProfile?->line_number ? " #L{$driver->driverProfile->line_number}" : '';
        $this->push->toUser(
            $booking->cso,
            'Karcis Siap Dicetak 🎫',
            trim(($driver->name ?? 'Supir') . $line) . ' menuju penumpang · ' . ($booking->zoneTo->name ?? 'Tujuan'),
            [
                'type'       => 'ticket_ready',
                'booking_id' => (string) $booking->id,
            ]
        );

        return true;
    }
}
