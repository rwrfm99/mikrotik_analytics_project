<?php

namespace App\Http\Controllers;

use App\Services\Mikrotik\ConnectionMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(ConnectionMonitor $mikrotik): JsonResponse
    {
        $services = ['api' => 'ok'];
        try {
            DB::select('SELECT 1');
            $services['database'] = 'ok';
        } catch (Throwable) {
            $services['database'] = 'error';
        }

        if (config('database.redis.client') === 'phpredis' && ! extension_loaded('redis')) {
            $services['redis'] = 'error';
        } else {
            try {
                Redis::connection()->ping();
                $services['redis'] = 'ok';
            } catch (Throwable) {
                $services['redis'] = 'error';
            }
        }

        try {
            $probe = $mikrotik->check();
            $services['mikrotik'] = $probe['status'];
        } catch (Throwable) {
            $probe = ['status' => 'error', 'reason' => 'monitor_unavailable'];
            $services['mikrotik'] = 'error';
        }
        $services['clickhouse'] = 'not_configured';
        $services['queue'] = 'not_monitored';
        $services['scheduler'] = 'not_monitored';
        $ready = ! in_array('error', $services, true);

        return response()->json([
            'status' => $ready ? 'ok' : 'degraded',
            'services' => $services,
            'mikrotik' => $probe,
        ], $ready ? 200 : 503);
    }
}
