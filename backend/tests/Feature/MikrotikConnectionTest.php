<?php

namespace Tests\Feature;

use App\Services\Mikrotik\ApiException;
use App\Services\Mikrotik\ConnectionMonitor;
use App\Services\Mikrotik\MikrotikApiClient;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class MikrotikConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_probe_is_cached_and_exposes_only_count(): void
    {
        $client = Mockery::mock(MikrotikApiClient::class);
        $client->shouldReceive('configured')->twice()->andReturn(true);
        $client->shouldReceive('activeUsers')->once()->andReturn([['user' => 'private-user', 'address' => '192.0.2.1']]);
        $monitor = new ConnectionMonitor($client);
        $result = $monitor->check();
        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['active_users']);
        $this->assertSame($result, $monitor->check());
        $this->assertArrayNotHasKey('user', $result);
    }

    public function test_authentication_error_is_not_reported_as_healthy(): void
    {
        $client = Mockery::mock(MikrotikApiClient::class);
        $client->shouldReceive('configured')->once()->andReturn(true);
        $client->shouldReceive('activeUsers')->once()->andThrow(new ApiException('authentication_failed'));
        $result = (new ConnectionMonitor($client))->check();
        $this->assertSame('error', $result['status']);
        $this->assertSame('authentication_failed', $result['reason']);
    }

    public function test_missing_credentials_do_not_attempt_network_access(): void
    {
        $client = Mockery::mock(MikrotikApiClient::class);
        $client->shouldReceive('configured')->once()->andReturn(false);
        $client->shouldNotReceive('activeUsers');
        $this->assertSame(['status' => 'not_configured'], (new ConnectionMonitor($client))->check());
    }
}
