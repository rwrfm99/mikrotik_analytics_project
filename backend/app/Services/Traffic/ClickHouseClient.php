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
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $msg = str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'Connection timed out')
                ? 'ClickHouse no respondió a tiempo (timeout de conexión).'
                : 'No se pudo conectar a ClickHouse. Verifica que el servicio esté activo.';
            throw new RuntimeException($msg, 0, $e);
        } catch (Throwable $e) {
            throw new RuntimeException('Error inesperado al comunicarse con ClickHouse.', 0, $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('Credenciales de ClickHouse incorrectas o sin permisos de lectura.');
        }
        if ($response->status() === 408 || str_contains((string) $response->body(), 'TIMEOUT_EXCEEDED')) {
            throw new RuntimeException('La consulta a ClickHouse tardó demasiado. Reduce el intervalo o espera unos segundos.');
        }
        if (! $response->successful()) {
            // Extraer el mensaje de error de ClickHouse si viene en el cuerpo de la respuesta.
            $body = (string) $response->body();
            $detail = preg_match('/Code:\s*\d+[.,]\s*(.+?)(?:\n|$)/s', $body, $m) ? trim($m[1]) : $body;
            $detail = mb_strimwidth($detail, 0, 200, '…');
            throw new RuntimeException("ClickHouse devolvió un error: {$detail}");
        }
        if (! is_array($response->json('data'))) {
            throw new RuntimeException('La respuesta de ClickHouse no tiene el formato esperado.');
        }

        return $response->json('data');
    }
}
