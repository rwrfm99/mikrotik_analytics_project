<?php

namespace App\Services\Traffic;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ClickHouseClient
{
    public function query(string $sql, array $parameters = []): array
    {
        // Queries are internal, fixed SELECT statements against our own database.
        if (! preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            throw new RuntimeException('Consulta no permitida.');
        }
        $params = ['default_format' => 'JSON', 'readonly' => 1];
        foreach ($parameters as $key => $value) {
            $params['param_'.$key] = $value;
        }
        try {
            $response = Http::withBasicAuth('traffic_reader', config('traffic.password') ?? '')
                ->connectTimeout(3)->timeout(20)->withOptions(['allow_redirects' => false])
                ->withBody($sql, 'text/plain')->post(rtrim(config('traffic.url'), '/').'/?'.http_build_query($params));
            if (! $response->successful() || ! is_array($response->json('data'))) {
                throw new RuntimeException('query_failed');
            }

            return $response->json('data');
        } catch (Throwable) {
            throw new RuntimeException('ClickHouse no disponible o la consulta supera los límites.');
        }
    }
}
