<?php

namespace Tests\Feature\Api;

use App\Enums\HarvestBatchStatus;
use App\Enums\UserRole;
use App\Models\HarvestBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FarmerSelectedRecipientTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_assigns_and_serializes_a_long_numeric_public_account_id(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'Budi',
            'last_name' => 'Petani',
            'phone' => '081234567890',
            'email' => 'public-id@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => UserRole::Petani->value,
            'village' => 'Pakis',
            'district' => 'Panti',
            'city' => 'Jember',
            'province' => 'Jawa Timur',
        ]);

        $response->assertCreated();
        $accountId = $response->json('data.user.public_account_id');
        $this->assertMatchesRegularExpression('/^[0-9]{16}$/', $accountId);
        $this->assertDatabaseHas('users', [
            'email' => 'public-id@example.com',
            'public_account_id' => $accountId,
        ]);
    }

    public function test_farmer_recipient_lookup_filters_by_role_and_active_status(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value);
        $collector = $this->makeUser(UserRole::Pengepul->value, ['first_name' => 'Ayu']);
        $this->makeUser(UserRole::Distributor->value);
        $this->makeUser(UserRole::Pengepul->value, ['is_active' => false]);

        $response = $this->asUser($farmer)
            ->getJson('/api/farmer/recipients?role=collector');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $collector->id)
            ->assertJsonPath('data.0.public_id', (string) $collector->id)
            ->assertJsonPath('data.0.first_name', $collector->first_name)
            ->assertJsonPath('data.0.last_name', $collector->last_name)
            ->assertJsonPath('data.0.full_name', $collector->full_name)
            ->assertJsonPath('data.0.role', 'collector')
            ->assertJsonStructure(['data' => [[
                'id', 'public_id', 'first_name', 'last_name', 'role',
            ]]]);

        $this->asUser($farmer)
            ->getJson('/api/farmer/recipients?role=konsumen')
            ->assertUnprocessable();
    }

    public function test_farmer_cannot_create_a_batch_for_a_mismatched_recipient_role(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value);
        $collector = $this->makeUser(UserRole::Pengepul->value);
        $inactiveUmkm = $this->makeUser(UserRole::Umkm->value, ['is_active' => false]);

        $response = $this->asUser($farmer)
            ->postJson('/api/farmer/batches', [
                'recipient_user_id' => $collector->id,
                'recipient_role' => UserRole::Distributor->value,
                'quantity_kg' => 25,
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['recipient_user_id']);

        $this->asUser($farmer)->postJson('/api/farmer/batches', [
            'recipient_user_id' => $inactiveUmkm->id,
            'recipient_role' => UserRole::Umkm->value,
            'quantity_kg' => 25,
        ])->assertUnprocessable()->assertJsonValidationErrors(['recipient_user_id']);

        $this->assertDatabaseCount('harvest_batches', 0);
    }

    public function test_collector_alias_is_accepted_for_batch_creation_and_exposed_in_json(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value);
        $collector = $this->makeUser(UserRole::Pengepul->value);

        $response = $this->asUser($farmer)->postJson('/api/farmer/batches', [
            'recipient_user_id' => $collector->id,
            'recipient_role' => 'collector',
            'quantity_kg' => 25,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.recipient_user_id', $collector->id)
            ->assertJsonPath('data.recipient_role', 'collector');

        $this->assertDatabaseHas('harvest_batches', [
            'recipient_user_id' => $collector->id,
            'recipient_role' => 'collector',
        ]);
    }

    public function test_farmer_can_create_a_batch_addressed_to_a_valid_account(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value);
        $recipient = $this->makeUser(UserRole::Umkm->value);

        $response = $this->asUser($farmer)->postJson('/api/farmer/batches', [
            'recipient_user_id' => $recipient->id,
            'recipient_role' => UserRole::Umkm->value,
            'quantity_kg' => 40,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('harvest_batches', [
            'code' => $response->json('data.code'),
            'farmer_user_id' => $farmer->id,
            'recipient_user_id' => $recipient->id,
            'recipient_role' => UserRole::Umkm->value,
            'status' => HarvestBatchStatus::Created->value,
        ]);
        $response->assertJsonPath('data.recipient_user_id', $recipient->id)
            ->assertJsonPath('data.recipient_role', UserRole::Umkm->value);

        $this->asUser($farmer)->getJson('/api/farmer/batches')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_only_the_selected_collector_can_list_and_verify_a_targeted_batch(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value);
        $target = $this->makeUser(UserRole::Pengepul->value);
        $otherCollector = $this->makeUser(UserRole::Pengepul->value);
        $batch = $this->makeBatch($farmer, $target, UserRole::Pengepul->value);
        $this->assertDatabaseHas('harvest_batches', [
            'id' => $batch->id,
            'recipient_user_id' => $target->id,
            'recipient_role' => UserRole::Pengepul->value,
            'status' => HarvestBatchStatus::Created->value,
        ]);

        $this->asUser($otherCollector)
            ->getJson('/api/collector/batches')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->asUser($otherCollector)
            ->getJson('/api/collector/stock')
            ->assertOk()
            ->assertJsonPath('data.active_batch_count', 0);

        $this->asUser($otherCollector)
            ->postJson('/api/collector/batches/'.$batch->code.'/verify', [
                'received_quantity_kg' => 25,
                'received_fruit_count' => 10,
            ])
            ->assertNotFound();

        $this->asUser($target)
            ->getJson('/api/collector/batches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $batch->code);
        $this->asUser($target)
            ->getJson('/api/collector/stock')
            ->assertOk()
            ->assertJsonPath('data.active_batch_count', 1);

        $this->asUser($target)
            ->postJson('/api/collector/batches/'.$batch->code.'/verify', [
                'received_quantity_kg' => 24,
                'received_fruit_count' => 9,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', HarvestBatchStatus::VerifiedByCollector->value);

        $this->assertDatabaseHas('harvest_batches', [
            'id' => $batch->id,
            'status' => HarvestBatchStatus::VerifiedByCollector->value,
            'verified_by_user_id' => $target->id,
        ]);
    }

    public function test_umkm_and_distributor_can_receive_only_batches_addressed_to_them(): void
    {
        foreach ([
            [UserRole::Umkm->value, '/api/umkm', HarvestBatchStatus::ReceivedByUmkm->value],
            [UserRole::Distributor->value, '/api/distributor', HarvestBatchStatus::ReceivedByDistributor->value],
        ] as [$role, $route, $receivedStatus]) {
            $farmer = $this->makeUser(UserRole::Petani->value);
            $target = $this->makeUser($role);
            $other = $this->makeUser($role);
            $batch = $this->makeBatch($farmer, $target, $role);
            $this->assertDatabaseHas('harvest_batches', [
                'id' => $batch->id,
                'recipient_user_id' => $target->id,
                'recipient_role' => $role,
            ]);

            $this->asUser($other)
                ->getJson($route.'/batches')
                ->assertOk()
                ->assertJsonCount(0, 'data');

            $this->asUser($other)
                ->postJson($route.'/batches/'.$batch->code.'/receive', [
                    'received_quantity_kg' => 25,
                    'received_fruit_count' => 10,
                ])
                ->assertNotFound();

            $this->asUser($target)
                ->postJson($route.'/batches/'.$batch->code.'/receive', [
                    'received_quantity_kg' => 25,
                    'received_fruit_count' => 10,
                ])
                ->assertOk()
                ->assertJsonPath('data.status', $receivedStatus);
        }
    }

    public function test_pending_targeted_batches_are_not_exposed_by_public_trace(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value);
        $collector = $this->makeUser(UserRole::Pengepul->value);
        $batch = $this->makeBatch($farmer, $collector, UserRole::Pengepul->value);

        $this->getJson('/api/trace/'.$batch->code)->assertNotFound();
    }

    public function test_public_trace_omits_recipient_identity_for_a_verified_targeted_batch(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value);
        $collector = $this->makeUser(UserRole::Pengepul->value);
        $batch = $this->makeBatch($farmer, $collector, UserRole::Pengepul->value);
        $batch->forceFill([
            'status' => HarvestBatchStatus::VerifiedByCollector->value,
            'verified_by_user_id' => $collector->id,
            'verified_at' => now(),
        ])->save();

        $response = $this->getJson('/api/trace/'.$batch->code)
            ->assertOk()
            ->assertJsonMissingPath('data.batch.recipient_user_id')
            ->assertJsonMissingPath('data.batch.recipient_role');

        $this->assertSame($batch->code, $response->json('data.batch.code'));

        $this->asUser($collector)
            ->getJson('/api/collector/batches')
            ->assertOk()
            ->assertJsonPath('data.0.recipient_user_id', $collector->id)
            ->assertJsonPath('data.0.recipient_role', 'collector');
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role' => $role]);
    }

    private function asUser(User $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function makeBatch(User $farmer, User $recipient, string $role): HarvestBatch
    {
        return HarvestBatch::create([
            'code' => 'DRN-TEST-'.fake()->unique()->numerify('######'),
            'farmer_user_id' => $farmer->id,
            'recipient_user_id' => $recipient->id,
            'recipient_role' => $role,
            'farm_name_snapshot' => 'Kebun Durian',
            'quantity_kg' => 25,
            'unit' => 'kg',
            'fruit_count' => 10,
            'status' => HarvestBatchStatus::Created->value,
        ]);
    }
}
