<?php

namespace Tests\Feature\Api;

use App\Enums\HarvestBatchStatus;
use App\Enums\UserRole;
use App\Models\HarvestBatch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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
            ->getJson('/api/farmer/recipients?role=collector&search=Ayu');

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
            ->getJson('/api/farmer/recipients?role=konsumen&search=123')
            ->assertUnprocessable();
    }

    public function test_collector_shipment_recipients_include_searchable_account_ids(): void
    {
        $collector = $this->makeUser(UserRole::Pengepul->value);
        $recipient = $this->makeUser(UserRole::Distributor->value, [
            'public_account_id' => '1234567890123456',
            'first_name' => 'Dina',
            'last_name' => 'Distributor',
        ]);

        $this->asUser($collector)
            ->getJson('/api/collector/shipment-recipients?role=distributor&search=1234567890123456')
            ->assertOk()
            ->assertJsonFragment([
                'userId' => (string) $recipient->id,
                'accountId' => '1234567890123456',
                'name' => $recipient->full_name,
                'destinationType' => 'distributor',
            ]);
    }

    public function test_collector_can_search_another_collector_but_never_a_farmer(): void
    {
        $collector = $this->makeUser(UserRole::Pengepul->value, [
            'first_name' => 'Pengepul',
            'last_name' => 'Satu',
        ]);
        $otherCollector = $this->makeUser(UserRole::Pengepul->value, [
            'first_name' => 'Pengepul',
            'last_name' => 'Dua',
        ]);

        $this->asUser($collector)
            ->getJson('/api/collector/shipment-recipients?role=collector&search=Dua')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.userId', (string) $otherCollector->id)
            ->assertJsonPath('data.0.destinationType', 'collector');

        $this->asUser($collector)
            ->getJson('/api/collector/shipment-recipients?role=petani&search=Fauzan')
            ->assertUnprocessable();
    }

    public function test_recipient_search_only_returns_active_accounts_of_the_selected_role(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value);
        $collector = $this->makeUser(UserRole::Pengepul->value, [
            'public_account_id' => '1234567890123456',
            'first_name' => 'Dina',
        ]);
        $this->makeUser(UserRole::Distributor->value, [
            'public_account_id' => '1234567890123457',
            'first_name' => 'Dina',
        ]);
        $this->makeUser(UserRole::Pengepul->value, [
            'public_account_id' => '1234567890999999',
            'first_name' => 'Dina',
            'is_active' => false,
        ]);

        $this->asUser($farmer)
            ->getJson('/api/farmer/recipients?role=collector&search=1234567890123456')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $collector->id);

        $this->asUser($farmer)
            ->getJson('/api/farmer/recipients?role=petani&search=Dina')
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

    public function test_farmer_batch_photo_is_uploaded_and_exposed_to_public_trace(): void
    {
        Storage::fake('public');

        $farmer = $this->makeUser(UserRole::Petani->value);
        $recipient = $this->makeUser(UserRole::Umkm->value);
        $photo = UploadedFile::fake()->createWithContent(
            'durian.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=')
        );

        $response = $this->asUser($farmer)->post('/api/farmer/batches', [
            'recipient_user_id' => $recipient->id,
            'recipient_role' => UserRole::Umkm->value,
            'quantity_kg' => 40,
            'photo' => $photo,
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.batch.photo_path', fn ($value) => str_starts_with($value, '/storage/uploads/batches/'))
            ->assertJsonPath('data.batch.photo_url', fn ($value) => str_starts_with($value, '/storage/uploads/batches/'));

        $storedPath = str_replace('/storage/', '', $response->json('data.batch.photo_path'));
        Storage::disk('public')->assertExists($storedPath);

        $this->asUser($recipient)->postJson('/api/umkm/batches/'.$response->json('data.code').'/receive', [
            'received_quantity_kg' => 40,
            'received_fruit_count' => 0,
        ])
            ->assertOk();

        $this->getJson('/api/trace/'.$response->json('data.code'))
            ->assertOk()
            ->assertJsonPath('data.batch.photo_path', $response->json('data.batch.photo_path'))
            ->assertJsonPath('data.batch.photo_url', $response->json('data.batch.photo_url'));
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
            ->assertJsonPath('data.active_batch_count', 0);

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

        $this->asUser($target)
            ->getJson('/api/collector/stock')
            ->assertOk()
            ->assertJsonPath('data.active_batch_count', 1)
            ->assertJsonPath('data.total_weight_kg', 24)
            ->assertJsonPath('data.total_fruit_count', 9);

        $batch->forceFill([
            'status' => HarvestBatchStatus::InDistribution->value,
        ])->save();

        $this->asUser($target)
            ->getJson('/api/collector/stock')
            ->assertOk()
            ->assertJsonPath('data.active_batch_count', 0)
            ->assertJsonPath('data.total_weight_kg', 0)
            ->assertJsonPath('data.total_fruit_count', 0);
    }

    public function test_collector_cannot_see_or_verify_legacy_batches_without_a_recipient(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value);
        $collector = $this->makeUser(UserRole::Pengepul->value);
        $batch = HarvestBatch::create([
            'code' => 'DRN-LEGACY-'.fake()->unique()->numerify('######'),
            'farmer_user_id' => $farmer->id,
            'farm_name_snapshot' => 'Kebun Lama',
            'quantity_kg' => 20,
            'unit' => 'kg',
            'fruit_count' => 8,
            'status' => HarvestBatchStatus::Created->value,
        ]);

        $this->asUser($collector)
            ->getJson('/api/collector/batches')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->asUser($collector)
            ->getJson('/api/collector/stock')
            ->assertOk()
            ->assertJsonPath('data.active_batch_count', 0);
        $this->asUser($collector)
            ->postJson('/api/collector/batches/'.$batch->code.'/verify', [
                'received_quantity_kg' => 20,
                'received_fruit_count' => 8,
            ])
            ->assertNotFound();
    }

    public function test_distributor_batch_list_includes_farmer_name(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value, [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
        ]);
        $distributor = $this->makeUser(UserRole::Distributor->value);
        $batch = $this->makeBatch($farmer, $distributor, UserRole::Distributor->value);

        $this->asUser($distributor)
            ->getJson('/api/distributor/batches')
            ->assertOk()
            ->assertJsonPath('data.0.code', $batch->code)
            ->assertJsonPath('data.0.farmer_name', 'Budi Santoso')
            ->assertJsonPath('data.0.farmerName', 'Budi Santoso');
    }

    public function test_collector_batch_list_includes_farmer_name(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value, [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
        ]);
        $collector = $this->makeUser(UserRole::Pengepul->value);
        $batch = $this->makeBatch($farmer, $collector, UserRole::Pengepul->value);

        $this->asUser($collector)
            ->getJson('/api/collector/batches')
            ->assertOk()
            ->assertJsonPath('data.0.code', $batch->code)
            ->assertJsonPath('data.0.farmer_name', 'Budi Santoso')
            ->assertJsonPath('data.0.farmerName', 'Budi Santoso');
    }

    public function test_farmer_can_delete_only_their_unverified_batch(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value);
        $otherFarmer = $this->makeUser(UserRole::Petani->value);
        $collector = $this->makeUser(UserRole::Pengepul->value);
        $pendingBatch = $this->makeBatch($farmer, $collector, UserRole::Pengepul->value);
        $verifiedBatch = $this->makeBatch($farmer, $collector, UserRole::Pengepul->value);
        $verifiedBatch->forceFill([
            'status' => HarvestBatchStatus::VerifiedByCollector->value,
        ])->save();
        $otherBatch = $this->makeBatch($otherFarmer, $collector, UserRole::Pengepul->value);

        $this->asUser($farmer)
            ->deleteJson('/api/farmer/batches/'.$pendingBatch->code)
            ->assertOk();
        $this->assertDatabaseMissing('harvest_batches', ['id' => $pendingBatch->id]);

        $this->asUser($farmer)
            ->deleteJson('/api/farmer/batches/'.$verifiedBatch->code)
            ->assertUnprocessable();
        $this->asUser($farmer)
            ->deleteJson('/api/farmer/batches/'.$otherBatch->code)
            ->assertNotFound();
        $this->assertDatabaseHas('harvest_batches', ['id' => $verifiedBatch->id]);
        $this->assertDatabaseHas('harvest_batches', ['id' => $otherBatch->id]);
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
            $receivePayload = [
                'received_quantity_kg' => 25,
                'received_fruit_count' => 10,
            ];
            if ($role === UserRole::Distributor->value) {
                $warehouse = Warehouse::create([
                    'owner_user_id' => $target->id,
                    'owner_role' => UserRole::Distributor->value,
                    'name' => 'Gudang Utama',
                    'location' => 'Jember',
                    'is_default' => true,
                ]);
                $receivePayload['warehouse_id'] = $warehouse->id;
            }
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
                ->postJson($route.'/batches/'.$batch->code.'/receive', $receivePayload)
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

    public function test_batch_creation_and_collector_verification_use_server_action_timestamps(): void
    {
        $farmer = $this->makeUser(UserRole::Petani->value);
        $collector = $this->makeUser(UserRole::Pengepul->value);
        $createdAt = Carbon::parse('2026-10-24 08:00:00', 'UTC');
        $verifiedAt = Carbon::parse('2026-10-24 10:30:00', 'UTC');

        $this->travelTo($createdAt);
        $created = $this->asUser($farmer)->postJson('/api/farmer/batches', [
            'recipient_user_id' => $collector->id,
            'recipient_role' => 'collector',
            'quantity_kg' => 25,
            'harvest_date' => '2026-10-23',
        ])->assertCreated()
            ->assertJsonPath('data.batch.created_at', $createdAt->toISOString())
            ->assertJsonPath('data.batch.harvest_date', '2026-10-23');

        $this->travelTo($verifiedAt);
        $this->asUser($collector)
            ->postJson('/api/collector/batches/'.$created->json('data.code').'/verify', [
                'received_quantity_kg' => 24,
                'received_fruit_count' => 9,
                'verified_at' => '2020-01-01T00:00:00Z',
            ])
            ->assertOk()
            ->assertJsonPath('data.batch.verified_at', $verifiedAt->toISOString());
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
