<?php

namespace Tests\Feature;

use App\Models\HotspotSession;
use App\Models\HotspotUser;
use App\Models\Router;
use App\Services\Mikrotik\ApiException;
use App\Services\Mikrotik\HotspotCollector;
use App\Services\Mikrotik\MikrotikApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class HotspotCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-02T10:00:00Z'));
        config(['cache.default' => 'array', 'mikrotik.collector_enabled' => true,
            'mikrotik.host' => '192.0.2.1', 'mikrotik.port' => 8728, 'mikrotik.missing_threshold' => 3]);
        Cache::flush();
    }

    private function row(array $changes = []): array
    {
        $seconds = 60 + (int) CarbonImmutable::parse('2026-10-02T10:00:00Z')->diffInSeconds(now());

        return array_replace(['.id' => '*1', 'user' => 'alice', 'address' => '192.168.9.50',
            'mac-address' => 'aa:bb:cc:dd:ee:01', 'uptime' => $seconds.'s', 'server' => 'hotspot',
            'bytes-in' => '1000', 'bytes-out' => '2000', 'packets-in' => '10', 'packets-out' => '20'], $changes);
    }

    private function collect(array|ApiException $rows, bool $failProfiles = false): array
    {
        $client = Mockery::mock(MikrotikApiClient::class);
        $expectation = $client->shouldReceive('activeUsers')->once();
        $rows instanceof ApiException ? $expectation->andThrow($rows) : $expectation->andReturn($rows);
        $profiles = $client->shouldReceive('hotspotUsers');
        $failProfiles ? $profiles->andThrow(new ApiException('request_rejected'))
            : $profiles->andReturn([['name' => 'alice', 'profile' => 'Internet', 'disabled' => 'false']]);

        return (new HotspotCollector($client))->collect();
    }

    public function test_repeated_polls_update_counters_without_duplicate_sessions(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame('ok', $this->collect([$this->row()])['status']);
        $this->travel(15)->seconds();
        $this->collect([$this->row(['bytes-in' => '5000'])]);
        $this->assertSame(1, HotspotSession::count());
        $session = HotspotSession::first();
        $this->assertSame(5000, (int) $session->mikrotik_bytes_in);
        $this->assertSame('2026-10-02 09:59:00', $session->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('Internet', HotspotUser::first()->profile);
    }

    public function test_disconnect_requires_three_successful_absences(): void
    {
        $this->collect([$this->row()]);
        for ($i = 1; $i <= 3; $i++) {
            $this->travel(15)->seconds();
            $this->collect([]);
            $this->assertSame($i, HotspotSession::first()->missing_polls);
            $this->assertSame($i === 3, HotspotSession::first()->ended_at !== null);
        }
        $this->assertSame('2026-10-02 10:00:00', HotspotSession::first()->ended_at->format('Y-m-d H:i:s'));
    }

    public function test_api_failure_does_not_close_sessions_or_count_absence(): void
    {
        $this->collect([$this->row()]);
        for ($i = 0; $i < 4; $i++) {
            $this->travel(15)->seconds();
            $this->assertSame('error', $this->collect(new ApiException('timeout'))['status']);
        }
        $this->assertNull(HotspotSession::first()->ended_at);
        $this->assertSame(0, HotspotSession::first()->missing_polls);
        $this->assertSame('timeout', Router::first()->collector_error);
        $this->collect([$this->row()]);
        $this->assertSame(1, HotspotSession::count());
        $this->assertNull(Router::first()->collector_error);
    }

    public function test_ip_change_preserves_old_session_and_creates_new_one(): void
    {
        $this->collect([$this->row()]);
        $this->travel(15)->seconds();
        $this->collect([$this->row(['address' => '192.168.8.77'])]);
        $this->assertSame(2, HotspotSession::count());
        $old = HotspotSession::orderBy('id')->first();
        $new = HotspotSession::orderByDesc('id')->first();
        $this->assertSame('192.168.9.50', $old->ip_address);
        $this->assertNotNull($old->ended_at);
        $this->assertSame('192.168.8.77', $new->ip_address);
        $this->assertNull($new->ended_at);
        $this->assertTrue($new->started_at->gt($old->ended_at));
    }

    public function test_reset_uptime_creates_a_new_session(): void
    {
        $this->collect([$this->row()]);
        $this->travel(15)->seconds();
        $this->collect([$this->row(['uptime' => '5s', 'bytes-in' => '1'])]);
        $this->assertSame(2, HotspotSession::count());
        $this->assertSame(1, HotspotSession::whereNull('ended_at')->count());
    }

    public function test_concurrent_devices_are_preserved_and_reused_ip_has_new_owner(): void
    {
        $this->collect([$this->row(), $this->row(['.id' => '*2', 'address' => '192.168.9.51', 'mac-address' => 'AA:BB:CC:DD:EE:02'])]);
        $this->assertSame(2, HotspotSession::whereNull('ended_at')->count());
        $this->travel(15)->seconds();
        $this->collect([$this->row(['user' => 'bob', 'uptime' => '1s']), $this->row(['.id' => '*2', 'address' => '192.168.9.51', 'mac-address' => 'AA:BB:CC:DD:EE:02'])]);
        $this->assertSame(3, HotspotSession::count());
        $this->assertSame(2, HotspotSession::whereNull('ended_at')->count());
        $this->assertSame(1, HotspotSession::where('username', 'bob')->whereNull('ended_at')->count());
    }

    public function test_invalid_snapshot_rolls_back_entire_poll(): void
    {
        $this->collect([$this->row()]);
        $this->travel(15)->seconds();
        $this->assertSame('error', $this->collect([$this->row(['bytes-in' => '5000']), $this->row(['.id' => '*2', 'address' => 'bad-ip'])])['status']);
        $this->assertSame(1000, (int) HotspotSession::first()->mikrotik_bytes_in);
        $this->assertSame(0, HotspotSession::first()->missing_polls);
    }

    public function test_profile_failure_does_not_discard_active_snapshot(): void
    {
        $this->assertSame('ok', $this->collect([$this->row()], true)['status']);
        $this->assertSame(1, HotspotSession::count());
        $this->assertNull(Router::first()->users_synced_at);
    }

    public function test_reappearance_resets_missing_count(): void
    {
        $this->collect([$this->row()]);
        $this->travel(15)->seconds();
        $this->collect([]);
        $this->travel(15)->seconds();
        $this->collect([$this->row()]);
        $this->assertSame(1, HotspotSession::count());
        $this->assertSame(0, HotspotSession::first()->missing_polls);
    }

    public function test_disabled_collector_and_lock_skip_network_access(): void
    {
        $client = Mockery::mock(MikrotikApiClient::class);
        $client->shouldNotReceive('activeUsers');
        config(['mikrotik.collector_enabled' => false]);
        $collector = new HotspotCollector($client);
        $this->assertSame('disabled', $collector->collect()['status']);
        config(['mikrotik.collector_enabled' => true]);
        $lock = Cache::lock('mikrotik:collector:'.hash('sha256', '192.0.2.1:8728'), 300);
        $lock->get();
        $this->assertSame('busy', $collector->collect()['status']);
        $lock->release();
    }

    public function test_api_returns_paginated_active_sessions_and_history(): void
    {
        $this->collect([$this->row()]);
        $this->getJson('/api/v1/hotspot/users/active?limit=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.user.profile', 'Internet');
        $this->getJson('/api/v1/hotspot/collection')->assertOk()->assertJsonPath('status', 'ok');
        $this->getJson('/api/v1/hotspot/sessions?limit=101')->assertUnprocessable();
        $this->getJson('/api/v1/hotspot/sessions?search=192.168.9.50')->assertOk()->assertJsonPath('total', 1);
        for ($i = 0; $i < 3; $i++) {
            $this->travel(15)->seconds();
            $this->collect([]);
        }
        $this->getJson('/api/v1/hotspot/users/active')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/hotspot/sessions')->assertOk()->assertJsonPath('total', 1);
    }
}
