<?php

namespace Tests\Feature\Api;

use App\Enums\HarvestBatchStatus;
use App\Enums\UserRole;
use App\Models\HarvestBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectorDailyIntakeSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_intake_summary_counts_received_batches_even_after_they_leave_active_stock(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value);
        $collector = $this->makeUser(UserRole::Pengepul->value);
        $otherCollector = $this->makeUser(UserRole::Pengepul->value);

        $this->makeReceivedBatch($farmer, $collector, [
            'status' => HarvestBatchStatus::InDistribution->value,
            'verified_at' => '2026-10-25 18:00:00',
            'quantity_kg' => 9,
            'received_quantity_kg' => 8.5,
        ]);
        $this->makeReceivedBatch($farmer, $collector, [
            'status' => HarvestBatchStatus::VerifiedByCollector->value,
            'verified_at' => '2026-10-26 16:59:59',
            'quantity_kg' => 12,
            'received_quantity_kg' => 11,
        ]);
        $this->makeReceivedBatch($farmer, $collector, [
            'status' => HarvestBatchStatus::InDistribution->value,
            'verified_at' => '2026-10-26 12:00:00',
            'quantity_kg' => 2.5,
            'received_quantity_kg' => null,
        ]);
        $this->makeReceivedBatch($farmer, $collector, [
            'status' => HarvestBatchStatus::VerifiedByCollector->value,
            'verified_at' => '2026-10-26 17:00:00',
            'quantity_kg' => 3,
            'received_quantity_kg' => 3,
        ]);
        $this->makeReceivedBatch($farmer, $otherCollector, [
            'status' => HarvestBatchStatus::VerifiedByCollector->value,
            'verified_at' => '2026-10-26 12:00:00',
            'quantity_kg' => 7,
            'received_quantity_kg' => 7,
        ]);

        $response = $this->asUser($collector)->getJson(
            '/api/collector/daily-intake-summary?'.http_build_query([
                'start_at' => '2026-10-25T17:00:00Z',
                'end_at' => '2026-10-26T17:00:00Z',
            ]),
        );

        $response->assertOk()
            ->assertJsonPath('data.batch_count', 3)
            ->assertJsonPath('data.total_weight_kg', 22);
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

    private function makeReceivedBatch(
        User $farmer,
        User $collector,
        array $attributes,
    ): HarvestBatch {
        return HarvestBatch::create($attributes + [
            'code' => 'DRN-TEST-'.fake()->unique()->numerify('######'),
            'farmer_user_id' => $farmer->id,
            'recipient_user_id' => $collector->id,
            'recipient_role' => 'collector',
            'farm_name_snapshot' => 'Kebun Durian',
            'quantity_kg' => 10,
            'unit' => 'kg',
            'fruit_count' => 4,
            'received_quantity_kg' => 10,
            'status' => HarvestBatchStatus::VerifiedByCollector->value,
            'verified_by_user_id' => $collector->id,
            'verified_at' => now(),
        ]);
    }
}
