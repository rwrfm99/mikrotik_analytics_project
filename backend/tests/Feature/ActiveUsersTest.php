<?php

namespace Tests\Feature;

use App\Models\Router;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_grouped_active_users_keep_all_devices_when_paginating_and_searching(): void
    {
        $router = Router::create(['name' => 'Test', 'host' => '192.0.2.1']);
        foreach (['alice', 'bob'] as $name) {
            $user = $router->users()->create(['username' => $name, 'profile' => 'Internet']);
            foreach ([1, 2, 3] as $device) {
                $user->sessions()->create([
                    'router_id' => $router->id, 'username' => $name,
                    'ip_address' => '192.168.9.'.$device,
                    'mac_address' => 'AA:BB:CC:DD:EE:0'.$device,
                    'started_at' => now(), 'last_seen_at' => now(),
                    'ended_at' => $device === 3 ? now() : null,
                    'mikrotik_bytes_in' => 100, 'mikrotik_bytes_out' => 200,
                ]);
            }
        }

        $this->getJson('/api/v1/hotspot/users/active?group_by=user&limit=1&search=alice')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.username', 'alice')
            ->assertJsonPath('data.0.profile', 'Internet')
            ->assertJsonPath('data.0.device_count', 2)
            ->assertJsonPath('data.0.mikrotik_bytes_in', 200)
            ->assertJsonPath('data.0.mikrotik_bytes_out', 400)
            ->assertJsonCount(2, 'data.0.sessions');
        $this->getJson('/api/v1/hotspot/users/active?group_by=user&limit=1&search=192.168.9.1')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('last_page', 2)
            ->assertJsonCount(2, 'data.0.sessions');
        $this->getJson('/api/v1/hotspot/users/active?group_by=user&limit=1&page=2')
            ->assertOk()->assertJsonPath('data.0.username', 'bob');
    }
}
