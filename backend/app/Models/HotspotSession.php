<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotspotSession extends Model
{
    protected $fillable = [
        'router_id', 'hotspot_user_id', 'username', 'ip_address', 'mac_address',
        'routeros_id', 'server_name', 'login_by', 'started_at', 'last_seen_at',
        'ended_at', 'missing_polls', 'uptime_seconds', 'mikrotik_bytes_in',
        'mikrotik_bytes_out', 'mikrotik_packets_in', 'mikrotik_packets_out',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime'];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(HotspotUser::class, 'hotspot_user_id');
    }
}
