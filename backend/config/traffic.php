<?php

return [
    'url' => env('CLICKHOUSE_HOST', 'http://clickhouse:8123'),
    'password' => env('CLICKHOUSE_READER_PASSWORD'),
    'receiver_url' => 'http://ipfix-collector:9001',
    'exporter' => env('IPFIX_EXPORTER', env('MIKROTIK_HOST', '192.168.10.1')),
];
