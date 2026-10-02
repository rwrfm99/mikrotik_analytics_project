<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotspotUser extends Model
{
    protected $fillable = ['router_id', 'username', 'profile', 'disabled', 'comment', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['disabled' => 'boolean', 'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(HotspotSession::class);
    }
}
