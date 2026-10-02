<?php

namespace App\Services\Mikrotik;

use App\Models\HotspotUser;
use App\Models\Router;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class HotspotCollector
{
    public function __construct(private MikrotikApiClient $client) {}

    public function collect(): array
    {
        if (! config('mikrotik.collector_enabled')) {
            return ['status' => 'disabled'];
        }
        // The lock spans network reads and the transaction. Timeout is bounded to < 120s.
        $lock = Cache::lock('mikrotik:collector:'.hash('sha256', config('mikrotik.host').':'.config('mikrotik.port')), 300);
        if (! $lock->get()) {
            return ['status' => 'busy'];
        }
        $router = null;
        try {
            $router = Router::firstOrCreate(
                ['host' => config('mikrotik.host'), 'api_port' => config('mikrotik.port')],
                ['name' => 'MikroTik Hotspot', 'enabled' => true],
            );
            if (! $router->enabled) {
                return ['status' => 'disabled'];
            }
            $observed = CarbonImmutable::now('UTC');
            // Only a complete, validated response can change sessions or missing counters.
            $snapshot = $this->validate($this->client->activeUsers());
            $result = $this->reconcile($router, $snapshot, $observed);

            // Profile synchronization is independent: its failure must not discard valid active data.
            if (! $router->users_synced_at || $router->users_synced_at->lt(now()->subMinute())) {
                try {
                    $this->syncUsers($router);
                } catch (Throwable) {
                    Log::warning('mikrotik.users_sync_failed', ['router_id' => $router->id]);
                }
            }
            Log::info('mikrotik.collected', ['router_id' => $router->id] + $result);

            return ['status' => 'ok'] + $result;
        } catch (Throwable $exception) {
            $code = $exception instanceof ApiException ? $exception->getMessage() : 'collector_failed';
            if ($router) {
                $router->forceFill(['collector_last_attempt_at' => now(), 'collector_error' => $code])->save();
            }
            Log::warning('mikrotik.collection_failed', ['router_id' => $router?->id, 'reason' => $code]);

            return ['status' => 'error', 'reason' => $code];
        } finally {
            $lock->release();
        }
    }

    private function validate(array $rows): array
    {
        $ids = [];
        foreach ($rows as &$row) {
            foreach (['.id', 'user', 'address', 'mac-address', 'uptime'] as $key) {
                if (! isset($row[$key]) || ! is_string($row[$key]) || $row[$key] === '' || strlen($row[$key]) > 255) {
                    throw new ApiException('invalid_snapshot');
                }
            }
            if (isset($ids[$row['.id']]) || ! filter_var($row['address'], FILTER_VALIDATE_IP)
                || ! preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/iD', $row['mac-address'])) {
                throw new ApiException('invalid_snapshot');
            }
            $ids[$row['.id']] = true;
            $row['seconds'] = RouterDuration::seconds($row['uptime']);
            $row['mac-address'] = strtoupper($row['mac-address']);
            foreach (['bytes-in', 'bytes-out', 'packets-in', 'packets-out'] as $counter) {
                if (isset($row[$counter]) && (! ctype_digit((string) $row[$counter]) || (float) $row[$counter] >= PHP_INT_MAX)) {
                    throw new ApiException('invalid_snapshot');
                }
            }
        }
        unset($row);

        return $rows;
    }

    private function reconcile(Router $router, array $rows, CarbonImmutable $observed): array
    {
        return DB::transaction(function () use ($router, $rows, $observed) {
            Router::whereKey($router->id)->lockForUpdate()->firstOrFail();
            $open = $router->sessions()->whereNull('ended_at')->lockForUpdate()->get();
            $seen = [];
            $created = 0;
            $closed = 0;
            foreach ($rows as $row) {
                $user = HotspotUser::firstOrCreate(
                    ['router_id' => $router->id, 'username' => $row['user']],
                    ['first_seen_at' => $observed],
                );
                $user->update(['last_seen_at' => $observed]);
                $session = $open->first(fn ($s) => ! $s->ended_at && $s->routeros_id === $row['.id']
                    && $s->username === $row['user'] && $s->ip_address === $row['address']
                    && $s->mac_address === $row['mac-address'] && $s->server_name === ($row['server'] ?? null));
                $estimatedStart = $observed->subSeconds($row['seconds']);
                if ($session && ($row['seconds'] < $session->uptime_seconds
                    || $estimatedStart->gt($session->last_seen_at->subSeconds($session->uptime_seconds)->addSeconds(5)))) {
                    // Reused RouterOS ID or router restart with a reset uptime.
                    $session->update(['ended_at' => $session->last_seen_at]);
                    $closed++;
                    $session = null;
                }
                $values = ['last_seen_at' => $observed, 'uptime_seconds' => $row['seconds'], 'missing_polls' => 0];
                foreach (['bytes-in', 'bytes-out', 'packets-in', 'packets-out'] as $counter) {
                    if (isset($row[$counter])) {
                        $values['mikrotik_'.str_replace('-', '_', $counter)] = (int) $row[$counter];
                    }
                }
                if (! $session) {
                    // Immediately retire an old identity replaced by an explicitly observed IP/ID change.
                    foreach ($open as $old) {
                        if (! $old->ended_at && ! isset($seen[$old->id]) &&
                            ($old->routeros_id === $row['.id'] ||
                            ($old->username === $row['user'] && $old->mac_address === $row['mac-address'] && $old->server_name === ($row['server'] ?? null)
                                && ! collect($rows)->contains(fn ($r) => $r['.id'] === $old->routeros_id)))) {
                            $old->update(['ended_at' => $old->last_seen_at]);
                            $closed++;
                        }
                    }
                    // Do not backdate a newly observed identity across stored history for this IP/device.
                    $hasHistory = $router->sessions()->where(function ($query) use ($row) {
                        $query->where('ip_address', $row['address'])->orWhere(function ($query) use ($row) {
                            $query->where('username', $row['user'])->where('mac_address', $row['mac-address']);
                        });
                    })->exists();
                    $session = $user->sessions()->create($values + [
                        'router_id' => $router->id, 'username' => $row['user'], 'routeros_id' => $row['.id'],
                        'ip_address' => $row['address'], 'mac_address' => $row['mac-address'],
                        'server_name' => $row['server'] ?? null, 'login_by' => $row['login-by'] ?? null,
                        'started_at' => $hasHistory ? $observed : $estimatedStart,
                    ]);
                    $created++;
                } else {
                    $session->update($values);
                }
                $seen[$session->id] = true;
            }
            foreach ($open as $session) {
                if (! $session->ended_at && ! isset($seen[$session->id])) {
                    $session->missing_polls++;
                    if ($session->missing_polls >= max(1, config('mikrotik.missing_threshold'))) {
                        $session->ended_at = $session->last_seen_at;
                        $closed++;
                    }
                    $session->save();
                }
            }
            $router->forceFill(['collector_last_success_at' => $observed, 'collector_last_attempt_at' => $observed, 'collector_error' => null])->save();

            return ['observed' => count($rows), 'created' => $created, 'closed' => $closed];
        });
    }

    private function syncUsers(Router $router): void
    {
        $rows = $this->client->hotspotUsers();
        DB::transaction(function () use ($router, $rows) {
            foreach ($rows as $row) {
                if (empty($row['name'])) {
                    continue;
                }
                $user = HotspotUser::firstOrCreate(['router_id' => $router->id, 'username' => $row['name']], ['first_seen_at' => now()]);
                $user->update(['profile' => $row['profile'] ?? null, 'disabled' => ($row['disabled'] ?? 'false') === 'true']);
            }
            $router->forceFill(['users_synced_at' => now()])->save();
        });
    }
}
