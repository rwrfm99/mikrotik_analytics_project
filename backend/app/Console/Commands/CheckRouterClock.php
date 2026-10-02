<?php

namespace App\Console\Commands;

use App\Services\Mikrotik\MikrotikApiClient;
use Illuminate\Console\Command;

class CheckRouterClock extends Command
{
    protected $signature = 'mikrotik:clock';
    protected $description = 'Lee el reloj del router sin modificarlo';

    public function handle(MikrotikApiClient $client): int
    {
        $this->line(json_encode(['pc_utc' => now()->utc()->toIso8601String(), 'router' => $client->clock()]));

        return self::SUCCESS;
    }
}
