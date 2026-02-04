<?php

use App\Models\Booking;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$driverId = 7; // Example Driver
$driver = User::find($driverId);

if (!$driver) { echo "Driver not found.\n"; exit; }

echo "Testing Self-Passenger Transaction Logic for Driver {$driver->name}...\n";

try {
    DB::beginTransaction();

    // 1. Create OnTrip Booking (Self Passenger)
    $booking = Booking::create([
        'cso_id'             => $driver->id,
        'driver_id'          => $driver->id,
        'zone_id'            => null,
        'manual_destination' => 'Test Dest',
        'price'              => 15000,
        'status'             => 'OnTrip',
    ]);
    
    echo "Booking Created: ID {$booking->id}\n";

    // 2. Simulate completeBooking Logic
    // Check condition: Self Order (CSO = Driver, Zone = Null)
    if ($booking->cso_id == $driver->id && is_null($booking->zone_id)) {
        echo "Condition Met: Creating Transaction...\n";
        
        $transaction = Transaction::create([
            'booking_id'    => $booking->id,
            'method'        => 'CashDriver',
            'amount'        => $booking->price,
            'payout_status' => 'Unpaid',
        ]);
        
        echo "Transaction Created: ID {$transaction->id} | Status: {$transaction->payout_status}\n";
    } else {
        echo "Condition FAILED! cso_id={$booking->cso_id} vs {$driver->id}, zone_id=".var_export($booking->zone_id, true)."\n";
    }

    $booking->update(['status' => 'Completed']);
    echo "Booking Completed.\n";

    DB::rollBack(); // Clean up
    echo "Rolled Back.\n";

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
