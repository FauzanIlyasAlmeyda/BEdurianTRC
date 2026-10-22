<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CollectorShipment;
use App\Models\User;

echo "=== TEST SHIPMENTS TO UMKM ===\n\n";

// 1. Cek semua shipment
$allShipments = CollectorShipment::all();
echo "Total shipments: " . $allShipments->count() . "\n\n";

// 2. Cek shipment ke UMKM
$umkmShipments = CollectorShipment::where('destination_type', 'umkm')->get();
echo "Shipments to UMKM: " . $umkmShipments->count() . "\n";

if ($umkmShipments->isEmpty()) {
    echo "❌ TIDAK ADA SHIPMENT KE UMKM!\n";
    echo "Buat shipment baru dari pengepul dengan destination = UMKM\n\n";
} else {
    echo "\n--- Detail Shipments ke UMKM ---\n";
    foreach ($umkmShipments as $shipment) {
        echo "Code: {$shipment->code}\n";
        echo "  Destination Type: {$shipment->destination_type}\n";
        echo "  Destination User ID: {$shipment->destination_user_id}\n";
        echo "  Destination Name: {$shipment->destination_name}\n";
        echo "  Status: {$shipment->status}\n";
        echo "  Weight: {$shipment->total_weight_kg} kg\n";
        echo "  Created: {$shipment->created_at}\n";
        echo "---\n";
    }
}

// 3. Cek user UMKM
echo "\n--- UMKM Users ---\n";
$umkmUsers = User::where('role', 'umkm')->get();

if ($umkmUsers->isEmpty()) {
    echo "❌ TIDAK ADA USER UMKM!\n";
} else {
    foreach ($umkmUsers as $user) {
        echo "ID: {$user->id}\n";
        echo "  Name: {$user->full_name}\n";
        echo "  Email: {$user->email}\n";
        
        // Cek shipment untuk user ini
        $userShipments = CollectorShipment::where('destination_type', 'umkm')
            ->where('destination_user_id', $user->id)
            ->whereIn('status', ['readyToShip', 'sent'])
            ->get();
        
        echo "  Incoming shipments: {$userShipments->count()}\n";
        
        if ($userShipments->isNotEmpty()) {
            echo "  ✅ Shipments codes: " . $userShipments->pluck('code')->join(', ') . "\n";
        }
        echo "---\n";
    }
}

echo "\n=== DIAGNOSIS ===\n";

// Check mismatch
$umkmShipments = CollectorShipment::where('destination_type', 'umkm')->get();
$umkmUserIds = User::where('role', 'umkm')->pluck('id')->toArray();

foreach ($umkmShipments as $shipment) {
    if (!in_array($shipment->destination_user_id, $umkmUserIds)) {
        echo "⚠️  WARNING: Shipment {$shipment->code} ditujukan ke user ID {$shipment->destination_user_id} tapi user tersebut tidak ada atau bukan UMKM!\n";
    }
}

// Check status
$wrongStatus = CollectorShipment::where('destination_type', 'umkm')
    ->whereNotIn('status', ['readyToShip', 'sent'])
    ->get();

if ($wrongStatus->isNotEmpty()) {
    echo "⚠️  WARNING: Ada " . $wrongStatus->count() . " shipment ke UMKM dengan status tidak valid:\n";
    foreach ($wrongStatus as $s) {
        echo "   - {$s->code}: {$s->status}\n";
    }
}

echo "\n=== END TEST ===\n";
