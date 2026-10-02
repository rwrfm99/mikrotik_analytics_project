<?php

namespace App\Console\Commands;

use App\Services\Traffic\ClickHouseClient;
use Illuminate\Console\Command;

class TrafficDiagnose extends Command
{
    protected $signature = 'traffic:diagnose';

    protected $description = 'Resumen técnico de IPFIX y DNS sin datos de usuarios';

    public function handle(ClickHouseClient $client): int
    {
        $this->line(json_encode($client->query('SELECT exporter, time_quality, direction, count() AS flows, min(start_ms) AS first_ms, max(end_ms) AS last_ms, min(flow_time) AS first_export, max(flow_time) AS last_export FROM traffic.flows FINAL GROUP BY exporter,time_quality,direction'), JSON_PRETTY_PRINT));
        $this->line(json_encode($client->query('SELECT status, count() AS records FROM traffic.dns FINAL GROUP BY status'), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
