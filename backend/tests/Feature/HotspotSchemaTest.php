<?php

namespace Tests\Feature;

use App\Models\HotspotUser;
use App\Models\Router;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotspotSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sessions_keep_previous_ip_and_timestamps(): void
    {
        $router = Router::create(['name' => 'Test', 'host' => '192.0.2.1']);
        $user = $router->users()->create(['username' => 'test-user']);
        $first = $user->sessions()->create([
            'router_id' => $router->id, 'username' => $user->username,
            'ip_address' => '192.168.9.50', 'mac_address' => 'AA:BB:CC:DD:EE:01',
            'started_at' => '2026-10-02 10:00:00', 'last_seen_at' => '2026-10-02 12:00:00',
            'ended_at' => '2026-10-02 12:00:00',
        ]);
        $user->sessions()->create([
            'router_id' => $router->id, 'username' => $user->username,
            'ip_address' => '192.168.8.77', 'mac_address' => 'AA:BB:CC:DD:EE:01',
            'started_at' => '2026-10-02 13:00:00', 'last_seen_at' => '2026-10-02 13:00:00',
        ]);

        $this->assertSame(2, $user->sessions()->count());
        $this->assertSame('192.168.9.50', $first->fresh()->ip_address);
        $this->assertSame('2026-10-02 12:00:00', $first->fresh()->ended_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $user->sessions()->whereNull('ended_at')->count());
    }

    public function test_username_is_unique_per_router(): void
    {
        $router = Router::create(['name' => 'Test', 'host' => '192.0.2.1']);
        HotspotUser::create(['router_id' => $router->id, 'username' => 'test-user']);
        $this->expectException(QueryException::class);
        HotspotUser::create(['router_id' => $router->id, 'username' => 'test-user']);
    }
}
