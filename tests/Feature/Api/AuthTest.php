<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_token(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'Budi',
            'last_name' => 'Petani',
            'phone' => '081234567890',
            'email' => 'budi@example.com',
            'username' => 'budi.petani',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'petani',
            'village' => 'Pakis',
            'district' => 'Panti',
            'city' => 'Jember',
            'province' => 'Jawa Timur',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.dashboard', 'petani')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user',
                    'token',
                    'dashboard',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'budi@example.com',
            'username' => 'budi.petani',
            'role' => 'petani',
        ]);
        $this->assertDatabaseHas('farmer_profiles', [
            'village' => 'Pakis',
            'district' => 'Panti',
            'city' => 'Jember',
            'province' => 'Jawa Timur',
        ]);
    }

    public function test_registration_requires_all_address_parts(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'Budi',
            'last_name' => 'Petani',
            'phone' => '081234567890',
            'email' => 'budi@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'petani',
            'village' => 'Pakis',
            'district' => 'Panti',
            'city' => 'Jember',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['province']);
        $this->assertDatabaseMissing('users', ['email' => 'budi@example.com']);
    }

    public function test_registration_creates_address_profile_for_every_role(): void
    {
        $profiles = [
            'petani' => 'farmer_profiles',
            'pengepul' => 'collector_profiles',
            'umkm' => 'umkm_profiles',
            'distributor' => 'distributor_profiles',
            'konsumen' => 'consumer_profiles',
        ];

        $phoneSuffix = 10;
        foreach ($profiles as $index => $table) {
            $response = $this->postJson('/api/register', [
                'first_name' => 'User',
                'last_name' => ucfirst($index),
                'phone' => '0812345678'.str_pad((string) $phoneSuffix++, 2, '0', STR_PAD_LEFT),
                'email' => "{$index}@example.com",
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => $index,
                'village' => 'Pakis',
                'district' => 'Panti',
                'city' => 'Jember',
                'province' => 'Jawa Timur',
            ]);

            $response->assertCreated();
            $this->assertDatabaseHas($table, [
                'village' => 'Pakis',
                'district' => 'Panti',
                'city' => 'Jember',
                'province' => 'Jawa Timur',
            ]);
        }
    }

    public function test_user_can_login_using_email_identifier(): void
    {
        $user = User::factory()->create([
            'email' => 'siti@example.com',
            'username' => 'siti.distributor',
            'role' => 'distributor',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'identifier' => 'siti@example.com',
            'password' => 'password123',
            'role' => 'distributor',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.role', 'distributor');
    }

    public function test_user_can_login_using_username_identifier(): void
    {
        User::factory()->create([
            'email' => 'andi@example.com',
            'username' => 'andi.pengepul',
            'role' => 'pengepul',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'identifier' => 'andi.pengepul',
            'password' => 'password123',
            'role' => 'pengepul',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.dashboard', 'pengepul')
            ->assertJsonPath('data.user.username', 'andi.pengepul');
    }

    public function test_authenticated_user_can_fetch_profile_and_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'andi@example.com',
            'username' => 'andi.pengepul',
            'role' => 'pengepul',
            'password' => 'password123',
        ]);

        $token = $user->createToken('testing');
        $personalAccessTokenId = $token->accessToken->id;

        $meResponse = $this->withToken($token->plainTextToken)->getJson('/api/me');

        $meResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'andi@example.com')
            ->assertJsonPath('data.dashboard', 'pengepul');

        $logoutResponse = $this->withToken($token->plainTextToken)->postJson('/api/logout');

        $logoutResponse
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing((new PersonalAccessToken())->getTable(), [
            'id' => $personalAccessTokenId,
        ]);
    }
}
