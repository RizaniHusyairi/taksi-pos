<?php

use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 1. Get a Driver User
// Change ID to an existing driver ID.
$driverId = 7; // Example
$driver = User::find($driverId);

if (!$driver) {
    echo "Driver not found.\n";
    exit;
}

echo "Testing Booking Creation for Driver {$driver->name} (ID: $driverId)...\n";

try {
    DB::beginTransaction();

    $booking = Booking::create([
        'cso_id'             => $driver->id,
        'driver_id'          => $driver->id,
        'zone_id'            => null,
        'manual_destination' => 'Test Destination',
        'price'              => 15000,
        'status'             => 'OnTrip',
    ]);

    echo "Booking Created Successfully! ID: " . $booking->id . "\n";
    
    // Rollback to keep DB clean
    DB::rollBack();
    echo "Transaction Rolled Back.\n";

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
