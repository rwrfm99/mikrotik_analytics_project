<?php

namespace App\Http\Controllers;

use App\Models\HotspotUser;
use App\Models\Router;
use App\Services\Traffic\ReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ReportController extends Controller
{
    private function parseInterval(Request $request): array
    {
        $input = $request->validate([
            'from'  => 'required|date',
            'to'    => 'required|date|after:from',
        ]);
        $from = CarbonImmutable::parse($input['from'])->utc();
        $to   = CarbonImmutable::parse($input['to'])->utc();
        if ($from->diffInHours($to) > 24 || $from->lt(now()->subDays(30)) || $to->gt(now()->addMinute())) {
            abort(422, 'Selecciona como máximo 24 horas dentro de los últimos 30 días.');
        }

        return [$from, $to];
    }

    public function userReport(Request $request, HotspotUser $user, ReportRepository $repository)
    {
        abort_unless($user->router->host === config('mikrotik.host'), 404);
        [$from, $to] = $this->parseInterval($request);
        try {
            return $repository->userReport($user, $from, $to);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (Throwable $e) {
            Log::error('Report user unexpected error', [
                'user_id' => $user->id,
                'from'    => $from->toIso8601String(),
                'to'      => $to->toIso8601String(),
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Error inesperado al generar el reporte.'], 503);
        }
    }

    public function topUsersReport(Request $request, ReportRepository $repository)
    {
        $router = Router::where('host', config('mikrotik.host'))->firstOrFail();
        [$from, $to] = $this->parseInterval($request);
        try {
            return $repository->topUsersReport($router->id, $from, $to);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (Throwable $e) {
            Log::error('Report top-users unexpected error', [
                'from'      => $from->toIso8601String(),
                'to'        => $to->toIso8601String(),
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Error inesperado al generar el reporte.'], 503);
        }
    }

    public function sitesReport(Request $request, ReportRepository $repository)
    {
        $router = Router::where('host', config('mikrotik.host'))->firstOrFail();
        [$from, $to] = $this->parseInterval($request);
        try {
            return $repository->sitesReport($router->id, $from, $to);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (Throwable $e) {
            Log::error('Report sites unexpected error', [
                'from'      => $from->toIso8601String(),
                'to'        => $to->toIso8601String(),
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Error inesperado al generar el reporte.'], 503);
        }
    }
}
