<?php

namespace Tests\Feature\Api;

use App\Enums\HarvestBatchStatus;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\HarvestBatch;
use App\Models\UmkmProduct;
use App\Models\UmkmProductSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UmkmOrderPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_umkm_can_delete_owned_product(): void
    {
        [$umkm, $product] = $this->createUmkmProduct();

        $this->withToken($umkm->createToken('testing')->plainTextToken)
            ->deleteJson('/api/umkm/products/'.$product->code)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('umkm_products', ['id' => $product->id]);
    }

    public function test_deleting_product_releases_its_unused_received_source_batch(): void
    {
        [$umkm, $product] = $this->createUmkmProduct();
        $farmer = User::factory()->create([
            'role' => UserRole::Petani->value,
        ]);
        $batch = HarvestBatch::create([
            'code' => 'DRN-TEST-001',
            'farmer_user_id' => $farmer->id,
            'farm_name_snapshot' => 'Kebun Uji',
            'variety' => 'Montong',
            'status' => HarvestBatchStatus::Processed->value,
            'recipient_user_id' => $umkm->id,
            'recipient_role' => UserRole::Umkm->value,
            'verified_by_user_id' => $umkm->id,
        ]);
        UmkmProductSource::create([
            'umkm_product_id' => $product->id,
            'harvest_batch_id' => $batch->id,
            'source_code_snapshot' => $batch->code,
            'weight_kg' => 10,
            'fruit_count' => 5,
        ]);

        $this->withToken($umkm->createToken('testing')->plainTextToken)
            ->deleteJson('/api/umkm/products/'.$product->code)
            ->assertOk();

        $this->assertDatabaseHas('harvest_batches', [
            'id' => $batch->id,
            'status' => HarvestBatchStatus::ReceivedByUmkm->value,
        ]);
        $this->assertDatabaseMissing('umkm_product_sources', [
            'harvest_batch_id' => $batch->id,
        ]);

        $this->withToken($umkm->createToken('materials')->plainTextToken)
            ->getJson('/api/umkm/material-batches')
            ->assertOk()
            ->assertJsonPath('data.0.code', $batch->code);
    }

    public function test_umkm_cannot_delete_another_umkms_product(): void
    {
        [, $product] = $this->createUmkmProduct();
        $otherUmkm = User::factory()->create([
            'role' => UserRole::Umkm->value,
        ]);

        $this->withToken($otherUmkm->createToken('testing')->plainTextToken)
            ->deleteJson('/api/umkm/products/'.$product->code)
            ->assertNotFound();

        $this->assertDatabaseHas('umkm_products', ['id' => $product->id]);
    }

    /**
     * @return array{User, UmkmProduct}
     */
    private function createUmkmProduct(): array
    {
        $umkm = User::factory()->create([
            'role' => UserRole::Umkm->value,
        ]);

        $product = UmkmProduct::create([
            'code' => 'UMKM-P-TEST-001',
            'umkm_user_id' => $umkm->id,
            'name' => 'Pancake Durian Premium',
            'price' => 68000,
            'stock_qty' => 3,
            'status' => ProductStatus::Aktif->value,
        ]);

        return [$umkm, $product];
    }
}
