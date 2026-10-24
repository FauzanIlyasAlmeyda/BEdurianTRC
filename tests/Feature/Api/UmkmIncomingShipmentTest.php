<?php

namespace Tests\Feature\Api;

use App\Enums\HarvestBatchStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\CollectorShipment;
use App\Models\CollectorShipmentSource;
use App\Models\HarvestBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UmkmIncomingShipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_distributor_shipment_responses_include_collector_name(): void
    {
        $collector = User::factory()->create([
            'first_name' => 'Misbah',
            'last_name' => 'Munir',
            'role' => UserRole::Pengepul->value,
        ]);
        $distributor = $this->makeUser(UserRole::Distributor->value);
        $shipment = CollectorShipment::create([
            'code' => 'PGL-COLLECTOR-NAME-001',
            'collector_user_id' => $collector->id,
            'destination_type' => UserRole::Distributor->value,
            'destination_user_id' => $distributor->id,
            'destination_name' => 'Distributor Uji',
            'destination_location' => 'Jember',
            'total_weight_kg' => 12,
            'total_fruit_count' => 4,
            'packaged_at' => now(),
            'sent_at' => now(),
            'status' => ShipmentStatus::Sent->value,
        ]);

        $this->asUser($distributor)
            ->getJson('/api/distributor/shipments')
            ->assertOk()
            ->assertJsonPath('data.0.collector_name', 'Misbah Munir');

        $this->asUser($distributor)
            ->getJson('/api/distributor/shipments/'.$shipment->code)
            ->assertOk()
            ->assertJsonPath('data.shipment.collector_name', 'Misbah Munir');
    }

    public function test_umkm_can_list_and_receive_its_sent_collector_shipments(): void
    {
        $umkm = $this->makeUser(UserRole::Umkm->value);
        $collector = $this->makeUser(UserRole::Pengepul->value);
        $batch = HarvestBatch::create([
            'code' => 'DRN-SHIPMENT-001',
            'farmer_user_id' => $this->makeUser(UserRole::Petani->value)->id,
            'recipient_user_id' => $collector->id,
            'recipient_role' => UserRole::Pengepul->value,
            'farm_name_snapshot' => 'Kebun Uji',
            'variety' => 'Montong',
            'quantity_kg' => 28,
            'unit' => 'kg',
            'fruit_count' => 10,
            'status' => HarvestBatchStatus::InDistribution->value,
        ]);
        $shipment = CollectorShipment::create([
            'code' => 'SHP-UMKM-001',
            'collector_user_id' => $collector->id,
            'destination_type' => UserRole::Umkm->value,
            'destination_user_id' => $umkm->id,
            'destination_name' => 'UMKM Uji',
            'destination_location' => 'Jember',
            'total_weight_kg' => 28,
            'total_fruit_count' => 10,
            'packaged_at' => now(),
            'sent_at' => now(),
            'status' => ShipmentStatus::Sent->value,
        ]);
        CollectorShipmentSource::create([
            'collector_shipment_id' => $shipment->id,
            'harvest_batch_id' => $batch->id,
            'source_code_snapshot' => $batch->code,
            'source_weight_kg' => 28,
            'source_fruit_count' => 10,
        ]);
        CollectorShipment::create([
            'code' => 'SHP-UMKM-READY-001',
            'collector_user_id' => $collector->id,
            'destination_type' => UserRole::Umkm->value,
            'destination_user_id' => $umkm->id,
            'destination_name' => 'UMKM Uji',
            'destination_location' => 'Jember',
            'total_weight_kg' => 20,
            'total_fruit_count' => 8,
            'packaged_at' => now(),
            'status' => ShipmentStatus::ReadyToShip->value,
        ]);
        CollectorShipment::create([
            'code' => 'SHP-OTHER-001',
            'collector_user_id' => $collector->id,
            'destination_type' => UserRole::Umkm->value,
            'destination_user_id' => $this->makeUser(UserRole::Umkm->value)->id,
            'destination_name' => 'UMKM Lain',
            'destination_location' => 'Bondowoso',
            'total_weight_kg' => 20,
            'total_fruit_count' => 8,
            'packaged_at' => now(),
            'sent_at' => now(),
            'status' => ShipmentStatus::Sent->value,
        ]);

        $this->asUser($umkm)
            ->getJson('/api/umkm/incoming-shipments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $shipment->code)
            ->assertJsonPath('data.0.source_batch_codes.0', $batch->code);

        $this->asUser($umkm)
            ->postJson('/api/umkm/incoming-shipments/'.$shipment->code.'/receive')
            ->assertOk()
            ->assertJsonPath('data.status', ShipmentStatus::Completed->value)
            ->assertJsonPath('data.shipment.code', $shipment->code);

        $this->asUser($umkm)
            ->postJson('/api/umkm/incoming-shipments/'.$shipment->code.'/receive')
            ->assertUnprocessable();

        $this->assertDatabaseHas('collector_shipments', [
            'id' => $shipment->id,
            'status' => ShipmentStatus::Completed->value,
        ]);
        $this->assertDatabaseHas('harvest_batches', [
            'id' => $batch->id,
            'status' => HarvestBatchStatus::ReceivedByUmkm->value,
            'recipient_user_id' => $umkm->id,
            'recipient_role' => UserRole::Umkm->value,
            'verified_by_user_id' => $umkm->id,
        ]);

        $this->asUser($umkm)
            ->getJson('/api/umkm/material-batches')
            ->assertOk()
            ->assertJsonFragment(['code' => $batch->code]);
    }

    public function test_umkm_cannot_receive_another_umkms_shipment(): void
    {
        $collector = $this->makeUser(UserRole::Pengepul->value);
        $shipment = CollectorShipment::create([
            'code' => 'SHP-UMKM-OTHER-001',
            'collector_user_id' => $collector->id,
            'destination_type' => UserRole::Umkm->value,
            'destination_user_id' => $this->makeUser(UserRole::Umkm->value)->id,
            'destination_name' => 'UMKM Pemilik',
            'destination_location' => 'Jember',
            'total_weight_kg' => 20,
            'total_fruit_count' => 8,
            'packaged_at' => now(),
            'sent_at' => now(),
            'status' => ShipmentStatus::Sent->value,
        ]);

        $this->asUser($this->makeUser(UserRole::Umkm->value))
            ->postJson('/api/umkm/incoming-shipments/'.$shipment->code.'/receive')
            ->assertNotFound();

        $this->assertDatabaseHas('collector_shipments', [
            'id' => $shipment->id,
            'status' => ShipmentStatus::Sent->value,
        ]);
    }

    private function makeUser(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function asUser(User $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
