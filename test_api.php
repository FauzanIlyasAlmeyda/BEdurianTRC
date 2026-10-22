<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

echo "=== TEST API ENDPOINTS ===\n\n";

// Login sebagai UMKM user
$umkmUser = User::where('email', 'umkm@duriantrace.com')->first();

if (!$umkmUser) {
    echo "❌ User UMKM tidak ditemukan!\n";
    exit(1);
}

echo "✅ User found: {$umkmUser->full_name} (ID: {$umkmUser->id})\n";
echo "Email: {$umkmUser->email}\n";
echo "Role: {$umkmUser->role}\n\n";

// Create token
$token = $umkmUser->createToken('test-token')->plainTextToken;
echo "🔑 Token: " . substr($token, 0, 20) . "...\n\n";

// Test endpoint /umkm/incoming-shipments
echo "--- Testing GET /api/umkm/incoming-shipments ---\n";

$baseUrl = 'http://127.0.0.1:8000/api';
$url = $baseUrl . '/umkm/incoming-shipments';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer ' . $token,
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

if ($error) {
    echo "❌ CURL Error: {$error}\n";
} else {
    echo "HTTP Status: {$httpCode}\n";
    echo "Response:\n";
    
    $json = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        
        if (isset($json['data']) && is_array($json['data'])) {
            echo "\n✅ Data is array with " . count($json['data']) . " items\n";
        }
    } else {
        echo $response . "\n";
    }
}

curl_close($ch);

echo "\n--- Testing GET /api/sales/incoming ---\n";

$url2 = $baseUrl . '/sales/incoming';
$ch2 = curl_init($url2);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer ' . $token,
]);
curl_setopt($ch2, CURLOPT_TIMEOUT, 10);

$response2 = curl_exec($ch2);
$httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);

echo "HTTP Status: {$httpCode2}\n";
$json2 = json_decode($response2, true);
if (json_last_error() === JSON_ERROR_NONE && isset($json2['data'])) {
    echo "Sales incoming count: " . count($json2['data']) . "\n";
}

curl_close($ch2);

echo "\n--- Testing GET /api/umkm/batches ---\n";

$url3 = $baseUrl . '/umkm/batches';
$ch3 = curl_init($url3);
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch3, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer ' . $token,
]);
curl_setopt($ch3, CURLOPT_TIMEOUT, 10);

$response3 = curl_exec($ch3);
$httpCode3 = curl_getinfo($ch3, CURLINFO_HTTP_CODE);

echo "HTTP Status: {$httpCode3}\n";
$json3 = json_decode($response3, true);
if (json_last_error() === JSON_ERROR_NONE && isset($json3['data'])) {
    echo "UMKM batches count: " . count($json3['data']) . "\n";
}

curl_close($ch3);

echo "\n=== END TEST ===\n";
