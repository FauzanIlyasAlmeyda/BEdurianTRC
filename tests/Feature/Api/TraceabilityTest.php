<?php

namespace Tests\Feature\Api;

use App\Enums\HarvestBatchStatus;
use App\Enums\UserRole;
use App\Models\CollectorShipment;
use App\Models\CollectorShipmentSource;
use App\Models\Farm;
use App\Models\HarvestBatch;
use App\Models\User;
use App\Support\Traceability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TraceabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_trace_includes_dummy_blockchain_proof(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Budi',
            'last_name' => 'Petani',
            'email' => 'budi.trace@example.com',
            'phone' => '081200000001',
            'role' => UserRole::Petani->value,
            'password' => 'password123',
        ]);

        $farm = Farm::create([
            'farmer_user_id' => $user->id,
            'name' => 'Kebun Durian Maju',
            'city' => 'Kediri',
            'province' => 'Jawa Timur',
        ]);

        $batch = HarvestBatch::create([
            'code' => 'DRN-2026-000001',
            'farmer_user_id' => $user->id,
            'farm_id' => $farm->id,
            'farm_name_snapshot' => $farm->name,
            'variety' => 'Montong',
            'grade' => 'A',
            'quantity_kg' => 120,
            'unit' => 'kg',
            'status' => HarvestBatchStatus::Created->value,
        ]);

        Traceability::recordEvent($batch, 'Batch Dibuat', $user, [
            'status' => HarvestBatchStatus::Created->value,
            'quantity_kg' => 120,
        ]);

        $response = $this->getJson('/api/trace/' . $batch->code);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.blockchain.network', 'dummy-ledger')
            ->assertJsonPath('data.blockchain.status', 'simulated')
            ->assertJsonPath('data.blockchain.event_count', 1)
            ->assertJsonPath('data.batch.code', 'DRN-2026-000001')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'batch',
                    'events',
                    'source_batches',
                    'downstream_products',
                    'consumer_transactions',
                    'shipment_history',
                    'public_url',
                    'blockchain',
                ],
            ]);
    }

    public function test_public_trace_includes_collector_shipment_message(): void
    {
        $farmer = User::factory()->create([
            'role' => UserRole::Petani->value,
        ]);
        $collector = User::factory()->create([
            'role' => UserRole::Pengepul->value,
        ]);
        $farm = Farm::create([
            'farmer_user_id' => $farmer->id,
            'name' => 'Kebun Uji',
            'city' => 'Kediri',
            'province' => 'Jawa Timur',
        ]);
        $batch = HarvestBatch::create([
            'code' => 'DRN-2026-000002',
            'farmer_user_id' => $farmer->id,
            'farm_id' => $farm->id,
            'farm_name_snapshot' => $farm->name,
            'variety' => 'Montong',
            'grade' => 'A',
            'quantity_kg' => 80,
            'unit' => 'kg',
            'status' => HarvestBatchStatus::Created->value,
        ]);
        $shipment = CollectorShipment::create([
            'code' => 'PGL-0001-000001',
            'collector_user_id' => $collector->id,
            'destination_type' => 'distributor',
            'total_weight_kg' => 80,
            'total_fruit_count' => 40,
            'packaged_at' => now(),
            'warehouse_note' => 'Dikirim dengan mobil berpendingin.',
            'status' => 'sent',
        ]);
        CollectorShipmentSource::create([
            'collector_shipment_id' => $shipment->id,
            'harvest_batch_id' => $batch->id,
            'source_code_snapshot' => $batch->code,
        ]);

        $this->getJson('/api/trace/' . $batch->code)
            ->assertOk()
            ->assertJsonPath(
                'data.shipment_history.0.warehouse_note',
                'Dikirim dengan mobil berpendingin.',
            );
    }
}
