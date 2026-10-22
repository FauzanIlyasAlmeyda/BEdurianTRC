<?php

// Debug script untuk cek shipment ke UMKM
// Usage: php artisan tinker
// >>> include 'debug_shipments.php';

use App\Models\CollectorShipment;
use App\Models\User;

echo "=== DEBUG SHIPMENTS TO UMKM ===\n\n";

// 1. Cek semua shipment yang ada
$allShipments = CollectorShipment::all();
echo "Total shipments: " . $allShipments->count() . "\n";

// 2. Cek shipment dengan destination_type = 'umkm'
$umkmShipments = CollectorShipment::where('destination_type', 'umkm')->get();
echo "Shipments to UMKM: " . $umkmShipments->count() . "\n\n";

// 3. Tampilkan detail shipment ke UMKM
if ($umkmShipments->isNotEmpty()) {
    echo "--- UMKM Shipments Detail ---\n";
    foreach ($umkmShipments as $shipment) {
        echo "Code: {$shipment->code}\n";
        echo "Destination Type: {$shipment->destination_type}\n";
        echo "Destination User ID: {$shipment->destination_user_id}\n";
        echo "Destination Name: {$shipment->destination_name}\n";
        echo "Status: {$shipment->status}\n";
        echo "Weight: {$shipment->total_weight_kg} kg\n";
        echo "---\n";
    }
}

// 4. Cek user UMKM yang ada
echo "\n--- UMKM Users ---\n";
$umkmUsers = User::where('role', 'umkm')->get();
foreach ($umkmUsers as $user) {
    echo "ID: {$user->id} | Name: {$user->full_name} | Email: {$user->email}\n";
    
    // Cek shipment untuk user ini
    $userShipments = CollectorShipment::where('destination_type', 'umkm')
        ->where('destination_user_id', $user->id)
        ->whereIn('status', ['readyToShip', 'sent'])
        ->count();
    echo "  → Incoming shipments: {$userShipments}\n";
}

echo "\n=== END DEBUG ===\n";
