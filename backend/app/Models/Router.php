<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Router extends Model
{
    protected $fillable = ['name', 'host', 'api_port', 'snmp_port', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'collector_last_success_at' => 'immutable_datetime',
            'collector_last_attempt_at' => 'immutable_datetime', 'users_synced_at' => 'immutable_datetime'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(HotspotUser::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(HotspotSession::class);
    }
}
