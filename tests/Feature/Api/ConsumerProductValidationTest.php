<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Farm;
use App\Models\HarvestBatch;
use App\Models\UmkmProduct;
use App\Models\UmkmProductSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConsumerProductValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumer_can_validate_an_umkm_product_once_and_read_their_validation(): void
    {
        $consumer = User::factory()->create(['role' => UserRole::Konsumen->value]);
        $product = $this->makeProduct();

        $this->asUser($consumer)
            ->getJson('/api/consumer/products/'.$product->code.'/validation')
            ->assertOk()
            ->assertJsonPath('data.validated', false)
            ->assertJsonPath('data.validated_at', null);

        $this->asUser($consumer)
            ->postJson('/api/consumer/products/'.$product->code.'/validation', [
                'confirmation' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.validated', true);

        $this->asUser($consumer)
            ->postJson('/api/consumer/products/'.$product->code.'/validation', [
                'confirmation' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.validated', true);

        $this->assertDatabaseCount('consumer_product_validations', 1);

        $this->asUser(User::factory()->create(['role' => UserRole::Konsumen->value]))
            ->getJson('/api/consumer/products/'.$product->code.'/validation')
            ->assertOk()
            ->assertJsonPath('data.validated', false);
    }

    public function test_only_consumer_accounts_can_validate_and_confirmation_is_required(): void
    {
        $product = $this->makeProduct();
        $consumer = User::factory()->create(['role' => UserRole::Konsumen->value]);
        $umkm = User::factory()->create(['role' => UserRole::Umkm->value]);

        $this->asUser($umkm)
            ->postJson('/api/consumer/products/'.$product->code.'/validation', [
                'confirmation' => true,
            ])
            ->assertForbidden();

        $this->asUser($consumer)
            ->postJson('/api/consumer/products/'.$product->code.'/validation', [
                'confirmation' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirmation']);
    }

    public function test_consumer_product_detail_exposes_source_batch_trace_codes(): void
    {
        $consumer = User::factory()->create(['role' => UserRole::Konsumen->value]);
        $farmer = User::factory()->create([
            'role' => UserRole::Petani->value,
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
        ]);
        $farm = Farm::create([
            'farmer_user_id' => $farmer->id,
            'name' => 'Kebun Durian Makmur',
        ]);
        $batch = HarvestBatch::create([
            'code' => 'DRN-2026-123456',
            'farmer_user_id' => $farmer->id,
            'farm_id' => $farm->id,
            'farm_name_snapshot' => $farm->name,
            'variety' => 'Montong',
            'grade' => 'A',
        ]);
        $product = $this->makeProduct();

        UmkmProductSource::create([
            'umkm_product_id' => $product->id,
            'harvest_batch_id' => $batch->id,
            'source_code_snapshot' => $batch->code,
            'weight_kg' => 5,
            'fruit_count' => 3,
        ]);

        $response = $this->asUser($consumer)
            ->getJson('/api/consumer/products/'.$product->code)
            ->assertOk();
        $response->assertJsonPath(
            'data.product.sourceMaterials.0.traceCode',
            $batch->code,
        )
            ->assertJsonPath(
                'data.product.trace_sources.0.batch_code',
                $batch->code,
            )
            ->assertJsonPath(
                'data.product.trace_sources.0.farmer_name',
                'Budi Santoso',
            )
            ->assertJsonPath(
                'data.product.trace_sources.0.farm_name',
                'Kebun Durian Makmur',
            )
            ->assertJsonPath(
                'data.product.trace_sources.0.variety',
                'Montong',
            )
            ->assertJsonPath('data.product.trace_sources.0.grade', 'A');
    }

    public function test_umkm_product_photo_is_saved_and_exposed_to_consumers(): void
    {
        Storage::fake('public');
        $umkm = User::factory()->create(['role' => UserRole::Umkm->value]);
        $consumer = User::factory()->create(['role' => UserRole::Konsumen->value]);

        $response = $this->asUser($umkm)->post('/api/umkm/products', [
            'name' => 'Durian Olahan',
            'source_codes' => [],
            'photo' => UploadedFile::fake()->createWithContent(
                'produk.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l8kAAAAASUVORK5CYII='),
            ),
        ]);
        $response->assertCreated()
            ->assertJsonPath('data.photo_url', fn ($url) => str_starts_with($url, '/storage/'));

        $path = $response->json('data.photo_path');
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('umkm_products', [
            'umkm_user_id' => $umkm->id,
            'photo_path' => $path,
        ]);

        $this->asUser($consumer)
            ->getJson('/api/consumer/products')
            ->assertOk()
            ->assertJsonPath('data.0.image_url', '/storage/'.$path);

        $this->asUser($consumer)
            ->getJson('/api/consumer/products/'.$response->json('data.code'))
            ->assertOk()
            ->assertJsonPath('data.product.image_url', '/storage/'.$path);
    }

    private function makeProduct(): UmkmProduct
    {
        $umkm = User::factory()->create(['role' => UserRole::Umkm->value]);

        return UmkmProduct::create([
            'code' => 'UMKM-P-'.fake()->unique()->numerify('######'),
            'umkm_user_id' => $umkm->id,
            'name' => 'Olahan Durian',
            'price' => 20000,
            'stock_qty' => 10,
            'status' => 'aktif',
        ]);
    }

    private function asUser(User $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
