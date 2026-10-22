<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_check_reports_server_and_database_as_healthy(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.services.server', 'ok')
            ->assertJsonPath('data.services.database', 'ok');
    }

    public function test_health_check_reports_database_failure_with_service_unavailable_status(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT 1')
            ->andThrow(new PDOException('Database connection failed'));

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.services.server', 'ok')
            ->assertJsonPath('data.services.database', 'error');
    }
}
