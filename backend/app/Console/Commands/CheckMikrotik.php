<?php

namespace App\Console\Commands;

use App\Services\Mikrotik\ConnectionMonitor;
use Illuminate\Console\Command;

class CheckMikrotik extends Command
{
    protected $signature = 'mikrotik:check';

    protected $description = 'Verifica login y lectura de Hotspot Active sin modificar RouterOS';

    public function handle(ConnectionMonitor $monitor): int
    {
        $result = $monitor->check(fresh: true);
        if ($result['status'] !== 'ok') {
            $this->error('MikroTik: '.($result['reason'] ?? $result['status']));

            return self::FAILURE;
        }
        $this->info('MikroTik: conexión y lectura correctas. Sesiones activas: '.$result['active_users']);

        return self::SUCCESS;
    }
}
