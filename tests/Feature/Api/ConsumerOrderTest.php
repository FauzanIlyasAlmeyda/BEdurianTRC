<?php

namespace Tests\Feature\Api;

use App\Enums\ConsumerTransactionStatus;
use App\Enums\HarvestBatchStatus;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\HarvestBatch;
use App\Models\HarvestBatchEvent;
use App\Models\UmkmProduct;
use App\Models\UmkmProductSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumerOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumer_order_reduces_stock_and_includes_trace_receipt(): void
    {
        $consumer = User::factory()->create(['role' => UserRole::Konsumen->value]);
        [$umkm, $product, $batch] = $this->createProductWithSource();
        $product->update(['photo_path' => 'products/order-photo.webp']);

        $response = $this->withToken($consumer->createToken('testing')->plainTextToken)
            ->postJson('/api/consumer/orders', [
                'product_code' => $product->code,
                'quantity' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', ConsumerTransactionStatus::Processing->value)
            ->assertJsonPath('data.product_name', $product->name)
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.total_amount', 136000)
            ->assertJsonPath(
                'data.product_image_url',
                fn ($url) => str_ends_with($url, '/storage/products/order-photo.webp')
            )
            ->assertJsonPath('data.trace_sources.0.batch_code', $batch->code)
            ->assertJsonPath('data.trace_sources.0.timeline.0.title', 'Batch dibuat petani')
            ->assertJsonPath('data.umkm_name', $umkm->full_name);

        $this->assertDatabaseHas('umkm_products', [
            'id' => $product->id,
            'stock_qty' => 1,
        ]);

        $traceTransaction = $this->getJson('/api/trace/' . $batch->code)
            ->assertOk()
            ->assertJsonPath(
                'data.consumer_transactions.0.code',
                $response->json('data.code')
            )
            ->json('data.consumer_transactions.0');
        $this->assertEqualsCanonicalizing([
            'code',
            'product_name',
            'quantity',
            'status',
            'created_at',
            'processed_at',
        ], array_keys($traceTransaction));

        $this->withToken($consumer->createToken('orders')->plainTextToken)
            ->getJson('/api/consumer/orders')
            ->assertOk()
            ->assertJsonPath('data.0.code', $response->json('data.code'));
    }

    public function test_consumer_can_cancel_order_once_and_stock_is_returned(): void
    {
        $consumer = User::factory()->create(['role' => UserRole::Konsumen->value]);
        [, $product, $batch] = $this->createProductWithSource();
        $token = $consumer->createToken('testing')->plainTextToken;
        $code = $this->withToken($token)
            ->postJson('/api/consumer/orders', [
                'product_code' => $product->code,
                'quantity' => 2,
            ])
            ->assertCreated()
            ->json('data.code');

        $this->withToken($token)
            ->postJson('/api/consumer/orders/'.$code.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', ConsumerTransactionStatus::Cancelled->value);

        $this->assertDatabaseHas('umkm_products', [
            'id' => $product->id,
            'stock_qty' => 3,
        ]);

        $this->getJson('/api/trace/' . $batch->code)
            ->assertOk()
            ->assertJsonCount(0, 'data.consumer_transactions');

        $this->withToken($token)
            ->postJson('/api/consumer/orders/'.$code.'/cancel')
            ->assertUnprocessable();

        $this->assertDatabaseHas('umkm_products', [
            'id' => $product->id,
            'stock_qty' => 3,
        ]);
    }

    public function test_consumer_can_validate_order_but_cannot_validate_another_consumers_order(): void
    {
        $consumer = User::factory()->create(['role' => UserRole::Konsumen->value]);
        $anotherConsumer = User::factory()->create(['role' => UserRole::Konsumen->value]);
        [, $product] = $this->createProductWithSource();
        $code = $this->withToken($consumer->createToken('testing')->plainTextToken)
            ->postJson('/api/consumer/orders', [
                'product_code' => $product->code,
                'quantity' => 1,
            ])
            ->assertCreated()
            ->json('data.code');

        $this->app['auth']->forgetGuards();
        $this->withToken($anotherConsumer->createToken('testing')->plainTextToken)
            ->postJson('/api/consumer/orders/'.$code.'/validate')
            ->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withToken($consumer->createToken('receipt')->plainTextToken)
            ->postJson('/api/consumer/orders/'.$code.'/validate')
            ->assertOk()
            ->assertJsonPath('data.status', ConsumerTransactionStatus::Completed->value)
            ->assertJsonPath('data.trace_sources.0.batch_code', 'DRN-ORDER-001');
    }

    public function test_order_receipt_keeps_trace_snapshot_if_umkm_later_deletes_product(): void
    {
        $consumer = User::factory()->create(['role' => UserRole::Konsumen->value]);
        [$umkm, $product] = $this->createProductWithSource();
        $product->update(['photo_path' => 'products/order-photo.webp']);
        $code = $this->withToken($consumer->createToken('testing')->plainTextToken)
            ->postJson('/api/consumer/orders', [
                'product_code' => $product->code,
                'quantity' => 1,
            ])
            ->assertCreated()
            ->json('data.code');

        $this->app['auth']->forgetGuards();
        $this->withToken($umkm->createToken('delete')->plainTextToken)
            ->deleteJson('/api/umkm/products/'.$product->code)
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($consumer->createToken('receipt')->plainTextToken)
            ->getJson('/api/consumer/orders')
            ->assertOk()
            ->assertJsonPath('data.0.code', $code)
            ->assertJsonPath('data.0.product_code', $product->code)
            ->assertJsonPath(
                'data.0.product_image_url',
                fn ($url) => str_ends_with($url, '/storage/products/order-photo.webp')
            )
            ->assertJsonPath('data.0.trace_sources.0.batch_code', 'DRN-ORDER-001');

        $this->getJson('/api/trace/DRN-ORDER-001')
            ->assertOk()
            ->assertJsonPath('data.consumer_transactions.0.code', $code);
    }

    public function test_consumer_cannot_order_inactive_or_unavailable_products(): void
    {
        $consumer = User::factory()->create(['role' => UserRole::Konsumen->value]);
        [, $product] = $this->createProductWithSource();
        $token = $consumer->createToken('testing')->plainTextToken;

        $product->update(['status' => ProductStatus::Nonaktif->value]);
        $this->withToken($token)
            ->postJson('/api/consumer/orders', [
                'product_code' => $product->code,
                'quantity' => 1,
            ])
            ->assertUnprocessable();

        $product->update([
            'status' => ProductStatus::Aktif->value,
            'stock_qty' => 1,
        ]);
        $this->withToken($token)
            ->postJson('/api/consumer/orders', [
                'product_code' => $product->code,
                'quantity' => 2,
            ])
            ->assertUnprocessable();
    }

    public function test_umkm_can_edit_own_product_and_hide_it_from_consumer_catalog(): void
    {
        [$umkm, $product] = $this->createProductWithSource();
        $consumer = User::factory()->create(['role' => UserRole::Konsumen->value]);

        $this->withToken($umkm->createToken('testing')->plainTextToken)
            ->putJson('/api/umkm/products/'.$product->code, [
                'name' => 'Durian Cup Baru',
                'price' => 75000,
                'stock_qty' => 8,
                'status' => ProductStatus::Nonaktif->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Durian Cup Baru')
            ->assertJsonPath('data.status', ProductStatus::Nonaktif->value);

        $this->app['auth']->forgetGuards();
        $this->withToken($consumer->createToken('testing')->plainTextToken)
            ->getJson('/api/consumer/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * @return array{User, UmkmProduct, HarvestBatch}
     */
    private function createProductWithSource(): array
    {
        $umkm = User::factory()->create(['role' => UserRole::Umkm->value]);
        $farmer = User::factory()->create(['role' => UserRole::Petani->value]);
        $batch = HarvestBatch::create([
            'code' => 'DRN-ORDER-001',
            'farmer_user_id' => $farmer->id,
            'farm_name_snapshot' => 'Kebun Trace',
            'variety' => 'Montong',
            'grade' => 'A',
            'status' => HarvestBatchStatus::Processed->value,
            'quantity_kg' => 10,
        ]);
        HarvestBatchEvent::create([
            'harvest_batch_id' => $batch->id,
            'title' => 'Batch dibuat petani',
            'actor_label' => $farmer->full_name,
            'actor_user_id' => $farmer->id,
            'event_at' => now(),
            'metadata' => ['status' => 'created'],
            'ledger_hash' => 'order-test-hash',
            'previous_ledger_hash' => '0',
            'ledger_height' => 1,
        ]);
        $product = UmkmProduct::create([
            'code' => 'UMKM-ORDER-001',
            'umkm_user_id' => $umkm->id,
            'name' => 'Pancake Durian Premium',
            'price' => 68000,
            'stock_qty' => 3,
            'status' => ProductStatus::Aktif->value,
        ]);
        UmkmProductSource::create([
            'umkm_product_id' => $product->id,
            'harvest_batch_id' => $batch->id,
            'source_code_snapshot' => $batch->code,
            'weight_kg' => 10,
            'fruit_count' => 5,
        ]);

        return [$umkm, $product, $batch];
    }
}
