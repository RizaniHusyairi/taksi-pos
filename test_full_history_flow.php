<?php

use App\Models\Booking;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\DriverApiController;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$driverId = 7; 
$driver = User::find($driverId);

echo "Testing History for Driver {$driver->name}...\n";

DB::beginTransaction();
try {
    // 1. Create Booking & Transaction
    $booking = Booking::create([
        'cso_id'             => $driver->id,
        'driver_id'          => $driver->id,
        'zone_id'            => null,
        'manual_destination' => 'History Test Dest',
        'price'              => 20000,
        'status'             => 'Completed',
    ]);
    
    $transaction = Transaction::create([
        'booking_id'    => $booking->id,
        'method'        => 'CashDriver',
        'amount'        => 20000,
        'payout_status' => 'Unpaid',
    ]);

    echo "Created Booking {$booking->id} & Transaction {$transaction->id}\n";

    // 2. Call Controller Method
    $controller = new DriverApiController();
    
    // Mock Request
    $request = Request::create('/api/driver/history', 'GET');
    $request->setUserResolver(function () use ($driver) {
        return $driver;
    });

    $response = $controller->getTripHistory($request);
    $history = $response->getData(); // JsonResponse content
    
    $found = false;
    echo "History Result Count: " . count($history) . "\n";
    
    foreach ($history as $item) {
        if ($item->booking_id == $booking->id) {
            $found = true;
            echo "Found Match: " . json_encode($item) . "\n";
            
            // Check manual destination presence
            $zoneName = $item->booking->zone_to ? $item->booking->zone_to->name : 'Manual/Null';
            $manualDest = $item->booking->manual_destination;
            echo "Zone: $zoneName | Manual: $manualDest\n";
            break;
        }
    }

    if ($found) {
        echo "SUCCESS: Self-Passenger Trip appears in history.\n";
    } else {
        echo "FAILURE: Trip NOT found in history.\n";
    }

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
} finally {
    DB::rollBack();
    echo "Rolled Back.\n";
}
