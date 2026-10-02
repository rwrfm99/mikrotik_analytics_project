<?php

namespace App\Http\Controllers;

use App\Models\HotspotUser;
use App\Services\Traffic\ClickHouseClient;
use App\Services\Traffic\TrafficRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TrafficController extends Controller
{
    public function users()
    {
        return HotspotUser::whereHas('router', fn ($q) => $q->where('host', config('mikrotik.host')))
            ->orderBy('username')->limit(1000)->get(['id', 'username']);
    }

    public function status(ClickHouseClient $client): array
    {
        $result = ['clickhouse' => 'error', 'receiver' => null];
        try {
            $row = $client->query('SELECT count() AS flows, sum(bytes) AS bytes, maxOrNull(received_at) AS last_received, argMax(toInt64(toUnixTimestamp(received_at)) - toInt64(toUnixTimestamp(flow_time)), received_at) AS clock_offset_seconds FROM traffic.flows')[0];
            $result = ['clickhouse' => 'ok', 'flows' => (int) $row['flows'], 'bytes' => (int) $row['bytes'],
                'last_received' => $row['last_received'], 'clock_offset_seconds' => (int) $row['clock_offset_seconds'], 'receiver' => null];
        } catch (Throwable) {
            // Do not expose connection credentials or internal query errors.
        }
        try {
            $response = Http::timeout(3)->get(config('traffic.receiver_url'));
            $result['receiver'] = $response->successful() ? $response->json() : null;
        } catch (Throwable) {
        }

        return $result;
    }

    public function report(Request $request, HotspotUser $user, TrafficRepository $repository)
    {
        abort_unless($user->router->host === config('mikrotik.host'), 404);
        $input = $request->validate(['from' => 'required|date', 'to' => 'required|date|after:from']);
        $from = CarbonImmutable::parse($input['from'])->utc();
        $to = CarbonImmutable::parse($input['to'])->utc();
        if ($from->diffInHours($to) > 24 || $from->lt(now()->subDays(30)) || $to->gt(now()->addMinute())) {
            return response()->json(['message' => 'Selecciona como máximo 24 horas dentro de los últimos 30 días.'], 422);
        }
        try {
            return $repository->report($user, $from, $to);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (Throwable $e) {
            Log::error('Traffic report unexpected error', [
                'user_id' => $user->id,
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'exception' => $e,
            ]);
            return response()->json(['message' => 'Error inesperado al generar el informe de tráfico.'], 503);
        }
    }
}
