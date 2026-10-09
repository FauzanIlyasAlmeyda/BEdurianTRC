<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\UmkmProduct;
use App\Models\UmkmProductSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $product = $this->makeProduct();
        $sourceCode = 'DRN-SOURCE-123456';

        UmkmProductSource::create([
            'umkm_product_id' => $product->id,
            'source_code_snapshot' => $sourceCode,
            'weight_kg' => 5,
            'fruit_count' => 3,
        ]);

        $response = $this->asUser($consumer)
            ->getJson('/api/consumer/products/'.$product->code)
            ->assertOk();
        $response->assertJsonPath(
            'data.product.sourceMaterials.0.traceCode',
            $sourceCode,
        );
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
