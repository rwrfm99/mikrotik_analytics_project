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

        // Collect the IPs belonging to the queried user's sessions for the WHERE filter.
        // This dramatically reduces the ClickHouse scan: instead of reading all flows for the
        // router we only read flows whose client_ip was used by this user in the interval.
        $userIps = [];
        foreach ($sessions as $session) {
            if ($session->hotspot_user_id === $user->id
                && filter_var($session->ip_address, FILTER_VALIDATE_IP)) {
                $userIps[$session->ip_address] = true;
            }
        }
        // Build ownership as a UNION ALL subquery so ClickHouse uses a hash join instead of
        // evaluating an arrayFilter per row, which would blow the per-query memory limit.
        // ClickHouse does not support VALUES(...) in a subquery context; use SELECT…UNION ALL instead.
        $unionRows = [];
        foreach ($sessions as $session) {
            if (! filter_var($session->ip_address, FILTER_VALIDATE_IP)) {
                continue;
            }
            // Open sessions are valid only through the last successful observation, never indefinitely.
            $upper = $session->ended_at ?? $session->last_seen_at;
            $unionRows[] = sprintf(
                "SELECT toUInt64(%d) AS session_id, '%s' AS ip, toUInt64(%d) AS sess_start, toUInt64(%d) AS sess_end, toUInt64(%d) AS owner_id",
                $session->id, $session->ip_address,
                $session->started_at->getTimestampMs(), $upper->getTimestampMs(), $session->hotspot_user_id
            );
        }
        $ownershipValues = $unionRows
            ? implode("\n  UNION ALL ", $unionRows)
            : "SELECT toUInt64(0) AS session_id, '' AS ip, toUInt64(0) AS sess_start, toUInt64(0) AS sess_end, toUInt64(0) AS owner_id WHERE 0 = 1";

        // If the user has no sessions with valid IPs in this interval, there is nothing to attribute.
        if (! $userIps) {
            return ['user' => ['id' => $user->id, 'username' => $user->username],
                'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
                'source' => 'IPFIX / ClickHouse local',
                'quality' => ['total_bytes' => 0, 'attributed_bytes' => 0, 'ambiguous_bytes' => 0,
                    'unknown_bytes' => 0, 'coverage_percent' => null],
                'upload_bytes' => 0, 'download_bytes' => 0, 'destinations' => []];
        }

        // The query is structured in three layers to avoid ClickHouse's "aggregate inside aggregate"
        // error that occurs when grouping over a FINAL ReplacingMergeTree with CTEs.
        // The innermost subquery (base) reads raw flows, the middle layer (ownership) counts
        // overlapping sessions per flow via a hash join, and the outer SELECT aggregates.
        $rows = $this->clickhouse->query(<<<SQL
SELECT
    user_id,
    confidence,
    destination_ip,
    sumIf(flow_bytes, direction = 'upload')   AS upload_bytes,
    sumIf(flow_bytes, direction = 'download') AS download_bytes,
    sum(flow_bytes)   AS bytes,
    sum(flow_packets) AS packets,
    count()           AS flows,
    min(flow_start)   AS first_ms,
    max(flow_end)     AS last_ms
FROM (
    SELECT
        destination_ip,
        direction,
        flow_bytes,
        flow_packets,
        flow_start,
        flow_end,
        owner_count,
        sole_owner,
        sole_sess_start,
        sole_sess_end,
        time_quality,
        sampling_rate,
        multiIf(
            owner_count > 1, 'ambiguous',
            owner_count = 1
                AND sole_sess_start <= flow_start
                AND sole_sess_end   >= flow_end
                AND time_quality = 'exporter'
                AND direction    != 'unknown'
                AND sampling_rate = 1, 'exact',
            'unknown'
        ) AS confidence,
        if(
            owner_count = 1
                AND sole_sess_start <= flow_start
                AND sole_sess_end   >= flow_end
                AND time_quality = 'exporter'
                AND direction    != 'unknown'
                AND sampling_rate = 1,
            sole_owner, toUInt64(0)
        ) AS user_id
    FROM (
        SELECT
            f.start_ms        AS flow_start,
            f.end_ms          AS flow_end,
            f.time_quality,
            f.direction,
            f.sampling_rate,
            f.destination_ip,
            f.bytes           AS flow_bytes,
            f.packets         AS flow_packets,
            countIf(o.owner_id != 0)              AS owner_count,
            anyIf(o.owner_id,   o.owner_id != 0)  AS sole_owner,
            anyIf(o.sess_start, o.owner_id != 0)  AS sole_sess_start,
            anyIf(o.sess_end,   o.owner_id != 0)  AS sole_sess_end
        FROM traffic.flows AS f
        LEFT JOIN ({$ownershipValues}) AS o
            ON  o.ip         = f.client_ip
            AND o.sess_start <= f.end_ms
            AND o.sess_end   >= f.start_ms
        WHERE f.exporter  = {exporter:String}
          AND f.flow_time >= fromUnixTimestamp({from_seconds:UInt32}) - INTERVAL 1 DAY
          AND f.flow_time <= fromUnixTimestamp({to_seconds:UInt32})   + INTERVAL 5 MINUTE
          AND f.end_ms    >= {from_ms:UInt64}
          AND f.end_ms    <  {to_ms:UInt64}
          AND f.client_ip IN {user_ips:Array(String)}
        GROUP BY
            f.start_ms, f.end_ms, f.time_quality, f.direction, f.sampling_rate,
            f.destination_ip, f.bytes, f.packets
    )
)
GROUP BY user_id, confidence, destination_ip
ORDER BY bytes DESC
LIMIT 10001
SQL, [
            'exporter'     => config('traffic.exporter'),
            'from_seconds' => $from->timestamp,
            'to_seconds'   => $to->timestamp,
            'from_ms'      => $from->getTimestampMs(),
            'to_ms'        => $to->getTimestampMs(),
            'user_ips'     => '['.implode(',', array_map(fn ($ip) => "'".$ip."'", array_keys($userIps))).']',
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
            $destinations[$ip] = [
                'ip'             => $ip,
                'upload_bytes'   => (int) $row['upload_bytes'],
                'download_bytes' => (int) $row['download_bytes'],
                'total_bytes'    => $bytes,
                'flows'          => (int) $row['flows'],
                'first_ms'       => (int) $row['first_ms'],
                'last_ms'        => (int) $row['last_ms'],
                'ptr'            => null,
                'dns_status'     => 'pending',
                'confidence'     => 'exact',
            ];
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
        $quality['coverage_percent'] = $quality['total_bytes']
            ? round(100 * $quality['attributed_bytes'] / $quality['total_bytes'], 2)
            : null;

        return ['user' => ['id' => $user->id, 'username' => $user->username], 'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(), 'source' => 'IPFIX / ClickHouse local', 'quality' => $quality,
            'upload_bytes'   => array_sum(array_column($destinations, 'upload_bytes')),
            'download_bytes' => array_sum(array_column($destinations, 'download_bytes')),
            'destinations'   => $destinations];
    }
}
