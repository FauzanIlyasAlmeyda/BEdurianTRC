<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsMiddlewareTest extends TestCase
{
    public function test_flutter_web_localhost_origin_with_dynamic_port_is_allowed(): void
    {
        $this->options('/api/collector/batches/DRN-2026-000001/verify', [], [
            'Origin' => 'http://localhost:50149',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, content-type',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:50149')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->assertHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');
    }

    public function test_disallowed_origin_is_not_reflected(): void
    {
        $response = $this->options('/api/collector/batches/DRN-2026-000001/verify', [], [
            'Origin' => 'https://malicious.example',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $response->assertNoContent();
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }
}
