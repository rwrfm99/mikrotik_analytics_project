<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class HealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['mikrotik.username' => null, 'mikrotik.password' => null]);
    }

    public function test_health_reports_ready_dependencies_and_pending_integrations(): void
    {
        config(['database.redis.client' => 'predis']);
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);
        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->once()->andReturn(true);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $this->getJson('/api/v1/health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('services.database', 'ok')
            ->assertJsonPath('services.mikrotik', 'not_configured')
            ->assertJsonPath('services.queue', 'not_monitored');
    }

    public function test_dependency_failure_returns_503_without_exception_details(): void
    {
        config(['database.redis.client' => 'predis']);
        DB::shouldReceive('select')->andThrow(new \RuntimeException('secret-password'));
        Redis::shouldReceive('connection')->andThrow(new \RuntimeException('internal-host'));

        $this->getJson('/api/v1/health')->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('services.database', 'error')
            ->assertJsonPath('services.redis', 'error')
            ->assertDontSee('secret-password')->assertDontSee('internal-host');
    }
}
