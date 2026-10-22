<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_accounts_and_address_profiles_without_demo_data(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('farmer_profiles', 1);
        $this->assertDatabaseCount('collector_profiles', 1);
        $this->assertDatabaseCount('distributor_profiles', 1);
        $this->assertDatabaseCount('umkm_profiles', 1);
        $this->assertDatabaseCount('consumer_profiles', 1);
        $this->assertDatabaseCount('farms', 0);
        $this->assertDatabaseCount('harvest_batches', 0);

        $this->assertDatabaseHas('farmer_profiles', [
            'village' => 'Jati',
            'district' => 'Sidoarjo',
            'city' => 'Kabupaten Sidoarjo',
            'province' => 'Jawa Timur',
        ]);
        $this->assertDatabaseHas('collector_profiles', [
            'village' => 'Genjeng',
            'district' => 'Loceret',
            'city' => 'Kabupaten Nganjuk',
            'province' => 'Jawa Timur',
        ]);
        $this->assertDatabaseHas('distributor_profiles', [
            'village' => 'Pacitan',
            'district' => 'Pacitan',
            'city' => 'Kabupaten Pacitan',
            'province' => 'Jawa Timur',
        ]);
        $this->assertDatabaseHas('umkm_profiles', [
            'village' => 'Plumpang',
            'district' => 'Plumpang',
            'city' => 'Kabupaten Tuban',
            'province' => 'Jawa Timur',
        ]);
        $this->assertDatabaseHas('consumer_profiles', [
            'village' => 'Kebonagung',
            'district' => 'Mejayan',
            'city' => 'Kabupaten Madiun',
            'province' => 'Jawa Timur',
        ]);
    }
}
