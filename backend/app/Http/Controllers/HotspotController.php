<?php

namespace App\Http\Controllers;

use App\Models\HotspotSession;
use App\Models\Router;
use Illuminate\Http\Request;

class HotspotController extends Controller
{
    public function status(): array
    {
        $router = Router::where('host', config('mikrotik.host'))->where('api_port', config('mikrotik.port'))->first();
        $last = $router?->collector_last_success_at;
        $fresh = $last && $last->gte(now()->subSeconds(max(60, 3 * config('mikrotik.poll_seconds'))));

        return [
            'enabled' => (bool) config('mikrotik.collector_enabled') && (! $router || $router->enabled),
            'status' => ! config('mikrotik.collector_enabled') || ($router && ! $router->enabled) ? 'disabled'
                : (! $last ? 'waiting' : ($router->collector_error ? 'error' : ($fresh ? 'ok' : 'stale'))),
            'last_success_at' => $last?->toIso8601String(),
            'last_attempt_at' => $router?->collector_last_attempt_at?->toIso8601String(),
            'error' => $router?->collector_error,
            'users_synced_at' => $router?->users_synced_at?->toIso8601String(),
            'poll_seconds' => max(5, (int) config('mikrotik.poll_seconds')),
            'source' => 'MikroTik RouterOS API',
            'users' => $router?->users()->count() ?? 0,
            'open_sessions' => $router?->sessions()->whereNull('ended_at')->count() ?? 0,
            'sessions' => $router?->sessions()->count() ?? 0,
        ];
    }

    public function active(Request $request)
    {
        return $this->sessions($request, true);
    }

    public function sessions(Request $request, bool $active = false)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100', 'router_id' => 'nullable|integer|min:1',
        ]);
        $query = HotspotSession::query()->with('user:id,profile')->select([
            'id', 'router_id', 'hotspot_user_id', 'username', 'ip_address', 'mac_address',
            'started_at', 'last_seen_at', 'ended_at', 'uptime_seconds', 'missing_polls',
            'mikrotik_bytes_in', 'mikrotik_bytes_out', 'server_name', 'login_by',
        ]);
        if ($active) {
            $query->whereNull('ended_at');
        }
        if (isset($filters['router_id'])) {
            $query->where('router_id', $filters['router_id']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.addcslashes($filters['search'], '\\%_').'%';
            $query->where(fn ($q) => $q->where('username', 'like', $term)
                ->orWhere('mac_address', 'like', $term)->orWhereRaw('CAST(ip_address AS TEXT) LIKE ?', [$term]));
        }

        return $query->orderByDesc('started_at')->orderByDesc('id')->paginate($filters['limit'] ?? 25);
    }
}
