<?php

return [
    'host' => env('MIKROTIK_HOST', '192.168.10.1'),
    'port' => (int) env('MIKROTIK_API_PORT', 8728),
    'username' => env('MIKROTIK_USERNAME'),
    'password' => env('MIKROTIK_PASSWORD'),
    'timeout_seconds' => (int) env('MIKROTIK_TIMEOUT_SECONDS', 5),
    'tls' => (bool) env('MIKROTIK_API_TLS', false),
    'local_network' => env('MIKROTIK_LOCAL_NETWORK', '192.168.8.0/22'),
    'poll_seconds' => (int) env('HOTSPOT_POLL_SECONDS', 15),
    'missing_threshold' => (int) env('HOTSPOT_MISSING_THRESHOLD', 3),
    'collector_enabled' => (bool) env('MIKROTIK_COLLECTOR_ENABLED', false),
];
