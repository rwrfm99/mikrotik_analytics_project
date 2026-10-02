<?php

namespace App\Services\Mikrotik;

use Illuminate\Support\Facades\Cache;

class ConnectionMonitor
{
    public function __construct(private MikrotikApiClient $client) {}

    public function check(bool $fresh = false): array
    {
        if (! $this->client->configured()) {
            return ['status' => 'not_configured'];
        }
        $key = 'mikrotik:connection:'.hash('sha256', json_encode([
            config('mikrotik.host'), config('mikrotik.port'), config('mikrotik.username'),
            config('mikrotik.password'), config('mikrotik.tls'),
        ]));
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 60, function () {
            try {
                $active = $this->client->activeUsers();

                return ['status' => 'ok', 'active_users' => count($active), 'checked_at' => now()->toIso8601String()];
            } catch (ApiException $exception) {
                return ['status' => 'error', 'reason' => $exception->getMessage(), 'checked_at' => now()->toIso8601String()];
            }
        });
    }
}
