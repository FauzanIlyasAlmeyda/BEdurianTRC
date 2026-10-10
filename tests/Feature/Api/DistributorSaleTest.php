<?php

namespace Tests\Feature\Api;

use App\Enums\HarvestBatchStatus;
use App\Enums\UserRole;
use App\Models\DistributorSale;
use App\Models\HarvestBatch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistributorSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_distributor_can_sell_only_its_received_stock_to_a_selected_account(): void
    {
        $seller = $this->makeUser(UserRole::Distributor->value);
        $buyer = $this->makeUser(UserRole::Umkm->value);
        $otherDistributor = $this->makeUser(UserRole::Distributor->value);
        $batch = $this->makeBatch($seller);
        $otherBatch = $this->makeBatch($otherDistributor, 'DRN-OTHER-'.fake()->unique()->numerify('######'));

        $this->asUser($seller)
            ->getJson('/api/distributor/sale-recipients?role=umkm&search='.$buyer->first_name)
            ->assertOk()
            ->assertJsonFragment([
                'id' => $buyer->id,
                'role' => UserRole::Umkm->value,
            ]);

        $this->asUser($seller)
            ->getJson('/api/distributor/sale-recipients?role=petani&search=any')
            ->assertUnprocessable();

        $this->asUser($seller)
            ->getJson('/api/distributor/sale-recipients?role=distributor&search='.$otherDistributor->first_name)
            ->assertOk()
            ->assertJsonFragment([
                'id' => $otherDistributor->id,
                'role' => UserRole::Distributor->value,
            ]);

        $this->asUser($seller)
            ->getJson('/api/distributor/inventory-batches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $batch->code);

        $this->asUser($seller)
            ->postJson('/api/distributor/sales', [
                'batch_code' => $otherBatch->code,
                'recipient_user_id' => $buyer->id,
                'recipient_role' => UserRole::Umkm->value,
            ])
            ->assertUnprocessable();

        $response = $this->asUser($seller)
            ->postJson('/api/distributor/sales', [
                'batch_code' => $batch->code,
                'recipient_user_id' => $buyer->id,
                'recipient_role' => UserRole::Umkm->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.buyer_distributor_id', (string) $buyer->id)
            ->assertJsonPath('data.buyer_role', UserRole::Umkm->value)
            ->assertJsonPath('data.item_code', $batch->code);

        $saleCode = $response->json('data.id');
        $this->assertDatabaseHas('distributor_sales', [
            'code' => $saleCode,
            'seller_user_id' => $seller->id,
            'recipient_user_id' => $buyer->id,
            'harvest_batch_id' => $batch->id,
            'status' => 'initiated',
        ]);

        $this->asUser($seller)
            ->getJson('/api/distributor/inventory-batches')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->asUser($this->makeUser(UserRole::Umkm->value))
            ->getJson('/api/sales/incoming')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->asUser($buyer)
            ->getJson('/api/sales/incoming')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $saleCode);

        $this->asUser($seller)
            ->postJson('/api/sales/'.$saleCode.'/receive', [
                'received_weight_kg' => 30,
                'received_fruit_count' => 12,
                'condition' => 'good',
            ])
            ->assertNotFound();

        $this->asUser($buyer)
            ->postJson('/api/sales/'.$saleCode.'/receive', [
                'received_weight_kg' => 29,
                'received_fruit_count' => 12,
                'condition' => 'good',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['discrepancy_note']);

        $this->asUser($buyer)
            ->postJson('/api/sales/'.$saleCode.'/receive', [
                'received_weight_kg' => 29,
                'received_fruit_count' => 12,
                'condition' => 'good',
                'discrepancy_note' => 'Berat kurang satu kilogram.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $this->assertDatabaseHas('harvest_batches', [
            'id' => $batch->id,
            'status' => HarvestBatchStatus::ReceivedByUmkm->value,
            'recipient_user_id' => $buyer->id,
            'recipient_role' => UserRole::Umkm->value,
            'verified_by_user_id' => $buyer->id,
        ]);
    }

    public function test_rejected_sale_returns_batch_to_distributor_inventory(): void
    {
        $seller = $this->makeUser(UserRole::Distributor->value);
        $buyer = $this->makeUser(UserRole::Pengepul->value);
        $batch = $this->makeBatch($seller);

        $response = $this->asUser($seller)
            ->postJson('/api/distributor/sales', [
                'batch_code' => $batch->code,
                'recipient_user_id' => $buyer->id,
                'recipient_role' => UserRole::Pengepul->value,
            ])
            ->assertCreated();
        $saleCode = $response->json('data.id');

        $this->asUser($buyer)
            ->postJson('/api/sales/'.$saleCode.'/reject', [
                'reason' => 'Kondisi tidak sesuai.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->asUser($seller)
            ->getJson('/api/distributor/inventory-batches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $batch->code);
        $this->assertDatabaseHas('harvest_batches', [
            'id' => $batch->id,
            'recipient_user_id' => $seller->id,
            'recipient_role' => UserRole::Distributor->value,
            'status' => HarvestBatchStatus::ReceivedByDistributor->value,
        ]);
    }

    public function test_farmer_batch_verification_assigns_owned_warehouse_and_moves_batch_to_inventory(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value);
        $distributor = $this->makeUser(UserRole::Distributor->value);
        $warehouse = Warehouse::create([
            'owner_user_id' => $distributor->id,
            'owner_role' => UserRole::Distributor->value,
            'name' => 'Gudang Utama',
            'location' => 'Jember',
            'is_default' => true,
        ]);
        $batch = HarvestBatch::create([
            'code' => 'DRN-INCOMING-'.fake()->unique()->numerify('######'),
            'farmer_user_id' => $farmer->id,
            'recipient_user_id' => $distributor->id,
            'recipient_role' => UserRole::Distributor->value,
            'farm_name_snapshot' => 'Kebun Uji',
            'variety' => 'Montong',
            'quantity_kg' => 30,
            'unit' => 'kg',
            'fruit_count' => 12,
            'status' => HarvestBatchStatus::Created->value,
        ]);

        $this->asUser($distributor)
            ->postJson('/api/distributor/batches/'.$batch->code.'/receive', [
                'received_quantity_kg' => 29,
                'received_fruit_count' => 12,
                'warehouse_id' => $warehouse->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', HarvestBatchStatus::ReceivedByDistributor->value)
            ->assertJsonPath('data.batch.warehouse_id', $warehouse->id);

        $this->assertDatabaseHas('harvest_batches', [
            'id' => $batch->id,
            'status' => HarvestBatchStatus::ReceivedByDistributor->value,
            'recipient_user_id' => $distributor->id,
            'recipient_role' => UserRole::Distributor->value,
            'warehouse_id' => $warehouse->id,
        ]);

        $this->asUser($distributor)
            ->getJson('/api/distributor/inventory-batches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $batch->code)
            ->assertJsonPath('data.0.warehouse_id', $warehouse->id);
    }

    private function makeUser(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function makeBatch(User $owner, ?string $code = null): HarvestBatch
    {
        return HarvestBatch::create([
            'code' => $code ?? 'DRN-OWNED-'.fake()->unique()->numerify('######'),
            'farmer_user_id' => $this->makeUser(UserRole::Petani->value)->id,
            'recipient_user_id' => $owner->id,
            'recipient_role' => UserRole::Distributor->value,
            'farm_name_snapshot' => 'Kebun Uji',
            'variety' => 'Montong',
            'quantity_kg' => 30,
            'unit' => 'kg',
            'fruit_count' => 12,
            'received_quantity_kg' => 30,
            'received_fruit_count' => 12,
            'verified_by_user_id' => $owner->id,
            'status' => HarvestBatchStatus::ReceivedByDistributor->value,
        ]);
    }

    private function asUser(User $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
