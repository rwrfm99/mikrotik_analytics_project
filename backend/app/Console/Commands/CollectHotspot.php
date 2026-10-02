<?php

namespace App\Console\Commands;

use App\Services\Mikrotik\HotspotCollector;
use Illuminate\Console\Command;
use Throwable;

class CollectHotspot extends Command
{
    protected $signature = 'mikrotik:collect {--once : Ejecutar un solo ciclo}';

    protected $description = 'Recolecta usuarios y sesiones Hotspot en modo de solo lectura';

    public function handle(HotspotCollector $collector): int
    {
        $running = true;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = function () use (&$running) {
                $running = false;
            };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }
        do {
            $start = microtime(true);
            try {
                $result = $collector->collect();
            } catch (Throwable) {
                $result = ['status' => 'error', 'reason' => 'storage_unavailable'];
            }
            $this->line(json_encode($result));
            if ($this->option('once')) {
                return $result['status'] === 'error' ? self::FAILURE : self::SUCCESS;
            }
            if ($running) {
                usleep((int) (max(1, max(5, (int) config('mikrotik.poll_seconds')) - (microtime(true) - $start)) * 1000000));
            }
        } while ($running);

        return self::SUCCESS;
    }
}
