<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\UmkmOrder;
use App\Models\UmkmProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UmkmOrderPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_umkm_can_persist_order_completion(): void
    {
        [$umkm, $product, $order] = $this->createUmkmOrder();

        $this->withToken($umkm->createToken('testing')->plainTextToken)
            ->patchJson('/api/umkm/orders/'.$order->code, [
                'status' => OrderStatus::Selesai->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Selesai->value);

        $this->assertDatabaseHas('umkm_orders', [
            'id' => $order->id,
            'status' => OrderStatus::Selesai->value,
        ]);
    }

    public function test_umkm_can_cancel_order_and_restore_product_stock(): void
    {
        [$umkm, $product, $order] = $this->createUmkmOrder();

        $this->withToken($umkm->createToken('testing')->plainTextToken)
            ->deleteJson('/api/umkm/orders/'.$order->code)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('umkm_orders', ['id' => $order->id]);
        $this->assertDatabaseHas('umkm_products', [
            'id' => $product->id,
            'stock_qty' => 5,
            'status' => ProductStatus::Aktif->value,
        ]);
    }

    public function test_umkm_can_delete_owned_product_and_its_orders(): void
    {
        [$umkm, $product, $order] = $this->createUmkmOrder();

        $this->withToken($umkm->createToken('testing')->plainTextToken)
            ->deleteJson('/api/umkm/products/'.$product->code)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('umkm_products', ['id' => $product->id]);
        $this->assertDatabaseMissing('umkm_orders', ['id' => $order->id]);
    }

    public function test_umkm_cannot_delete_another_umkms_product(): void
    {
        [, $product] = $this->createUmkmOrder();
        $otherUmkm = User::factory()->create([
            'role' => UserRole::Umkm->value,
        ]);

        $this->withToken($otherUmkm->createToken('testing')->plainTextToken)
            ->deleteJson('/api/umkm/products/'.$product->code)
            ->assertNotFound();

        $this->assertDatabaseHas('umkm_products', ['id' => $product->id]);
    }

    /**
     * @return array{User, UmkmProduct, UmkmOrder}
     */
    private function createUmkmOrder(): array
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

        $order = UmkmOrder::create([
            'code' => 'ORD-TEST-001',
            'umkm_product_id' => $product->id,
            'buyer_name' => 'Rina Saputri',
            'buyer_address' => 'Jember',
            'quantity' => 2,
            'total_amount' => 136000,
            'status' => OrderStatus::Diproses->value,
        ]);

        return [$umkm, $product, $order];
    }
}
