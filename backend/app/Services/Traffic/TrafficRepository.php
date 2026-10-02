<?php

namespace App\Services\Traffic;

use App\Models\HotspotSession;
use App\Models\HotspotUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class TrafficRepository
{
    public function __construct(private ClickHouseClient $clickhouse) {}

    public function report(HotspotUser $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = 'traffic:report:'.hash('sha256', $user->id.'|'.$from->timestamp.'|'.$to->timestamp);

        return Cache::remember($key, 15, fn () => $this->build($user, $from, $to));
    }

    private function build(HotspotUser $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Include every account on the router, not just the selected one: overlapping ownership must be ambiguous.
        $sessions = HotspotSession::where('router_id', $user->router_id)->where('started_at', '<=', $to)
            ->where('last_seen_at', '>=', $from->subDay())->limit(10001)->get();
        if ($sessions->count() > 10000) {
            throw new RuntimeException('Demasiadas sesiones; reduce el intervalo.');
        }
        $tuples = [];
        foreach ($sessions as $session) {
            if (! filter_var($session->ip_address, FILTER_VALIDATE_IP)) {
                continue;
            }
            // Open sessions are valid only through the last successful observation, never indefinitely.
            $upper = $session->ended_at ?? $session->last_seen_at;
            $tuples[] = sprintf("(%d,'%s',%d,%d,%d)", $session->id, $session->ip_address,
                $session->started_at->getTimestampMs(), $upper->getTimestampMs(), $session->hotspot_user_id);
        }
        $rows = $this->clickhouse->query(<<<'SQL'
WITH {sessions:Array(Tuple(UInt64,String,UInt64,UInt64,UInt64))} AS ownership,
 matched AS (
   SELECT start_ms, end_ms, time_quality, direction, sampling_rate, destination_ip, protocol, bytes, packets,
     arrayFilter(s -> s.2 = client_ip AND s.3 <= end_ms AND s.4 >= start_ms, ownership) AS matches
   FROM traffic.flows FINAL
   WHERE exporter = {exporter:String}
     AND flow_time >= fromUnixTimestamp({from_seconds:UInt32}) - INTERVAL 1 DAY
     AND flow_time <= fromUnixTimestamp({to_seconds:UInt32}) + INTERVAL 5 MINUTE
     AND end_ms >= {from_ms:UInt64} AND end_ms < {to_ms:UInt64}
 ), classified AS (
   SELECT start_ms, end_ms, direction, destination_ip, protocol, bytes, packets, matches,
     multiIf(length(matches) > 1, 'ambiguous',
      length(matches) = 1 AND matches[1].3 <= start_ms AND matches[1].4 >= end_ms
      AND time_quality = 'exporter' AND direction != 'unknown' AND sampling_rate = 1, 'exact', 'unknown') AS confidence
   FROM matched
 )
SELECT if(confidence = 'exact', matches[1].5, toUInt64(0)) AS user_id,
 confidence, destination_ip, direction, protocol,
 sum(bytes) AS bytes, sum(packets) AS packets, count() AS flows,
 min(start_ms) AS first_ms, max(end_ms) AS last_ms
FROM classified
GROUP BY user_id, confidence, destination_ip, direction, protocol
ORDER BY bytes DESC
LIMIT 10001
SQL, [
            'sessions' => '['.implode(',', $tuples).']',
            'exporter' => config('traffic.exporter'),
            'from_seconds' => $from->timestamp, 'to_seconds' => $to->timestamp,
            'from_ms' => $from->getTimestampMs(), 'to_ms' => $to->getTimestampMs(),
        ]);
        if (count($rows) >= 10001) {
            throw new RuntimeException('Demasiados destinos; reduce el intervalo.');
        }
        $quality = ['total_bytes' => 0, 'attributed_bytes' => 0, 'ambiguous_bytes' => 0, 'unknown_bytes' => 0];
        $destinations = [];
        foreach ($rows as $row) {
            $bytes = (int) $row['bytes'];
            $quality['total_bytes'] += $bytes;
            $quality[match ($row['confidence']) {
                'exact' => 'attributed_bytes', 'ambiguous' => 'ambiguous_bytes', default => 'unknown_bytes'
            }] += $bytes;
            if ((int) $row['user_id'] !== $user->id || $row['confidence'] !== 'exact') {
                continue;
            }
            $ip = $row['destination_ip'];
            if (! isset($destinations[$ip])) {
                $destinations[$ip] = ['ip' => $ip, 'upload_bytes' => 0, 'download_bytes' => 0,
                    'total_bytes' => 0, 'flows' => 0, 'first_ms' => (int) $row['first_ms'], 'last_ms' => 0,
                    'ptr' => null, 'dns_status' => 'pending', 'confidence' => 'exact'];
            }
            $destinations[$ip][$row['direction'].'_bytes'] += $bytes;
            $destinations[$ip]['total_bytes'] += $bytes;
            $destinations[$ip]['flows'] += (int) $row['flows'];
            $destinations[$ip]['first_ms'] = min($destinations[$ip]['first_ms'], (int) $row['first_ms']);
            $destinations[$ip]['last_ms'] = max($destinations[$ip]['last_ms'], (int) $row['last_ms']);
        }
        if ($destinations) {
            $ips = array_filter(array_keys($destinations), fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP));
            $dnsRows = $this->clickhouse->query('SELECT ip, ptr, status FROM traffic.dns FINAL WHERE expires_at > now() AND ip IN {ips:Array(String)}',
                ['ips' => '['.implode(',', array_map(fn ($ip) => "'".$ip."'", $ips)).']']);
            foreach ($dnsRows as $dns) {
                if (isset($destinations[$dns['ip']])) {
                    $destinations[$dns['ip']]['ptr'] = $dns['ptr'] ?: null;
                    $destinations[$dns['ip']]['dns_status'] = $dns['status'];
                }
            }
        }
        usort($destinations, fn ($a, $b) => $b['total_bytes'] <=> $a['total_bytes']);
        $quality['coverage_percent'] = $quality['total_bytes'] ? round(100 * $quality['attributed_bytes'] / $quality['total_bytes'], 2) : null;

        return ['user' => ['id' => $user->id, 'username' => $user->username], 'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(), 'source' => 'IPFIX / ClickHouse local', 'quality' => $quality,
            'upload_bytes' => array_sum(array_column($destinations, 'upload_bytes')),
            'download_bytes' => array_sum(array_column($destinations, 'download_bytes')),
            'destinations' => $destinations];
    }
}
