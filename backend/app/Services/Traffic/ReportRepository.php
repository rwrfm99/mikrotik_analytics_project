<?php

namespace App\Services\Traffic;

use App\Models\HotspotSession;
use App\Models\HotspotUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ReportRepository
{
    public function __construct(private ClickHouseClient $clickhouse) {}

    // -------------------------------------------------------------------------
    // Report per user: top destinations by bytes, with time spent per site.
    // "Time spent" = sum of individual flow durations (end_ms - start_ms) for
    // flows with time_quality = 'exporter'. Flows without exporter timestamps
    // contribute bytes but not duration (duration counted as 0).
    // -------------------------------------------------------------------------
    public function userReport(HotspotUser $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = 'report:user:'.hash('sha256', $user->id.'|'.$from->timestamp.'|'.$to->timestamp);

        return Cache::remember($key, 30, fn () => $this->buildUserReport($user, $from, $to));
    }

    // -------------------------------------------------------------------------
    // Top users by consumed bytes in the interval.
    // Returns up to 20 users ranked by total bytes, with upload/download split.
    // -------------------------------------------------------------------------
    public function topUsersReport(int $routerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = 'report:top_users:'.hash('sha256', $routerId.'|'.$from->timestamp.'|'.$to->timestamp);

        return Cache::remember($key, 30, fn () => $this->buildTopUsersReport($routerId, $from, $to));
    }

    // -------------------------------------------------------------------------
    // Global sites report: top destinations across all users in the interval,
    // ranked by total bytes. Includes unique user count per destination.
    // -------------------------------------------------------------------------
    public function sitesReport(int $routerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = 'report:sites:'.hash('sha256', $routerId.'|'.$from->timestamp.'|'.$to->timestamp);

        return Cache::remember($key, 30, fn () => $this->buildSitesReport($routerId, $from, $to));
    }

    private function buildTopUsersReport(int $routerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Load all sessions on the router in the interval to build ownership.
        $sessions = HotspotSession::where('router_id', $routerId)
            ->where('started_at', '<=', $to)
            ->where('last_seen_at', '>=', $from->subDay())
            ->limit(10001)->get();
        if ($sessions->count() > 10000) {
            throw new RuntimeException('Demasiadas sesiones; reduce el intervalo.');
        }

        $unionRows = [];
        foreach ($sessions as $session) {
            if (! filter_var($session->ip_address, FILTER_VALIDATE_IP)) {
                continue;
            }
            $upper       = $session->ended_at ?? $session->last_seen_at;
            $unionRows[] = sprintf(
                "SELECT toUInt64(%d) AS session_id, '%s' AS ip, toUInt64(%d) AS sess_start, toUInt64(%d) AS sess_end, toUInt64(%d) AS owner_id",
                $session->id, $session->ip_address,
                $session->started_at->getTimestampMs(), $upper->getTimestampMs(), $session->hotspot_user_id
            );
        }

        if (! $unionRows) {
            return [
                'from'        => $from->toIso8601String(),
                'to'          => $to->toIso8601String(),
                'total_bytes' => 0,
                'users'       => [],
            ];
        }

        $ownershipValues = implode("\n  UNION ALL ", $unionRows);

        // Query: aggregate exact-confidence flows grouped by owner_id (= hotspot_user_id).
        $rows = $this->clickhouse->query(<<<SQL
SELECT
    sole_owner                             AS user_id,
    sumIf(flow_bytes, direction = 'download') AS download_bytes,
    sumIf(flow_bytes, direction = 'upload')   AS upload_bytes,
    sum(flow_bytes)                            AS total_bytes,
    count()                                    AS flows
FROM (
    SELECT
        f.bytes     AS flow_bytes,
        f.direction,
        countIf(o.owner_id != 0)              AS owner_count,
        anyIf(o.owner_id,   o.owner_id != 0)  AS sole_owner,
        anyIf(o.sess_start, o.owner_id != 0)  AS sole_sess_start,
        anyIf(o.sess_end,   o.owner_id != 0)  AS sole_sess_end,
        f.time_quality,
        f.sampling_rate,
        f.start_ms,
        f.end_ms
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
    GROUP BY f.start_ms, f.end_ms, f.time_quality, f.direction, f.sampling_rate, f.bytes
)
WHERE owner_count = 1
  AND sole_sess_start <= start_ms
  AND sole_sess_end   >= end_ms
  AND time_quality = 'exporter'
  AND direction   != 'unknown'
  AND sampling_rate = 1
  AND sole_owner != 0
GROUP BY sole_owner
ORDER BY total_bytes DESC
LIMIT 20
SQL, [
            'exporter'     => config('traffic.exporter'),
            'from_seconds' => $from->timestamp,
            'to_seconds'   => $to->timestamp,
            'from_ms'      => $from->getTimestampMs(),
            'to_ms'        => $to->getTimestampMs(),
        ]);

        // Resolve hotspot usernames from MySQL.
        $userIds   = array_map(fn ($r) => (int) $r['user_id'], $rows);
        $usernames = HotspotUser::whereIn('id', $userIds)->pluck('username', 'id');

        $users      = [];
        $grandTotal = 0;
        foreach ($rows as $row) {
            $uid         = (int) $row['user_id'];
            $totalBytes  = (int) $row['total_bytes'];
            $grandTotal += $totalBytes;
            $users[]     = [
                'user_id'        => $uid,
                'username'       => $usernames[$uid] ?? "id:{$uid}",
                'download_bytes' => (int) $row['download_bytes'],
                'upload_bytes'   => (int) $row['upload_bytes'],
                'total_bytes'    => $totalBytes,
                'flows'          => (int) $row['flows'],
            ];
        }

        return [
            'from'        => $from->toIso8601String(),
            'to'          => $to->toIso8601String(),
            'total_bytes' => $grandTotal,
            'users'       => $users,
        ];
    }

    // =========================================================================

    private function buildUserReport(HotspotUser $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Load sessions for the router to resolve ownership (same logic as TrafficRepository).
        $sessions = HotspotSession::where('router_id', $user->router_id)
            ->where('started_at', '<=', $to)
            ->where('last_seen_at', '>=', $from->subDay())
            ->limit(10001)->get();
        if ($sessions->count() > 10000) {
            throw new RuntimeException('Demasiadas sesiones; reduce el intervalo.');
        }

        $userIps = [];
        $unionRows = [];
        foreach ($sessions as $session) {
            if (! filter_var($session->ip_address, FILTER_VALIDATE_IP)) {
                continue;
            }
            $upper = $session->ended_at ?? $session->last_seen_at;
            $row = sprintf(
                "SELECT toUInt64(%d) AS session_id, '%s' AS ip, toUInt64(%d) AS sess_start, toUInt64(%d) AS sess_end, toUInt64(%d) AS owner_id",
                $session->id, $session->ip_address,
                $session->started_at->getTimestampMs(), $upper->getTimestampMs(), $session->hotspot_user_id
            );
            $unionRows[] = $row;
            if ($session->hotspot_user_id === $user->id) {
                $userIps[$session->ip_address] = true;
            }
        }

        if (! $userIps) {
            return $this->emptyUserReport($user, $from, $to);
        }

        $ownershipValues = $unionRows
            ? implode("\n  UNION ALL ", $unionRows)
            : "SELECT toUInt64(0) AS session_id, '' AS ip, toUInt64(0) AS sess_start, toUInt64(0) AS sess_end, toUInt64(0) AS owner_id WHERE 0 = 1";

        $rows = $this->clickhouse->query(<<<SQL
SELECT
    destination_ip,
    sumIf(flow_bytes, direction = 'upload')   AS upload_bytes,
    sumIf(flow_bytes, direction = 'download') AS download_bytes,
    sum(flow_bytes)                            AS total_bytes,
    sum(flow_packets)                          AS packets,
    count()                                    AS flows,
    -- duration_ms: sum of (end-start) for flows with exporter-quality timestamps only
    sumIf(flow_end - flow_start, tq = 'exporter') AS duration_ms,
    min(flow_start)                            AS first_ms,
    max(flow_end)                              AS last_ms
FROM (
    SELECT
        destination_ip,
        direction,
        tq,
        flow_bytes,
        flow_packets,
        flow_start,
        flow_end,
        owner_count,
        sole_owner,
        sole_sess_start,
        sole_sess_end,
        sampling_rate,
        if(
            owner_count = 1
                AND sole_sess_start <= flow_start
                AND sole_sess_end   >= flow_end
                AND tq = 'exporter'
                AND direction    != 'unknown'
                AND sampling_rate = 1,
            sole_owner, toUInt64(0)
        ) AS user_id
    FROM (
        SELECT
            f.start_ms        AS flow_start,
            f.end_ms          AS flow_end,
            f.time_quality    AS tq,
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
    WHERE user_id = {user_id:UInt64}
)
GROUP BY destination_ip
ORDER BY total_bytes DESC
LIMIT 500
SQL, [
            'exporter'     => config('traffic.exporter'),
            'from_seconds' => $from->timestamp,
            'to_seconds'   => $to->timestamp,
            'from_ms'      => $from->getTimestampMs(),
            'to_ms'        => $to->getTimestampMs(),
            'user_ips'     => '['.implode(',', array_map(fn ($ip) => "'".$ip."'", array_keys($userIps))).']',
            'user_id'      => (string) $user->id,
        ]);

        // Enrich with PTR records from the DNS cache.
        $destinations = [];
        foreach ($rows as $row) {
            $destinations[$row['destination_ip']] = [
                'ip'             => $row['destination_ip'],
                'upload_bytes'   => (int) $row['upload_bytes'],
                'download_bytes' => (int) $row['download_bytes'],
                'total_bytes'    => (int) $row['total_bytes'],
                'flows'          => (int) $row['flows'],
                'duration_ms'    => (int) $row['duration_ms'],
                'first_ms'       => (int) $row['first_ms'],
                'last_ms'        => (int) $row['last_ms'],
                'ptr'            => null,
                'dns_status'     => 'pending',
            ];
        }
        $this->enrichDns($destinations);

        return [
            'user'           => ['id' => $user->id, 'username' => $user->username],
            'from'           => $from->toIso8601String(),
            'to'             => $to->toIso8601String(),
            'total_bytes'    => array_sum(array_column($destinations, 'total_bytes')),
            'upload_bytes'   => array_sum(array_column($destinations, 'upload_bytes')),
            'download_bytes' => array_sum(array_column($destinations, 'download_bytes')),
            'destinations'   => array_values($destinations),
        ];
    }

    private function buildSitesReport(int $routerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // For the sites report we need all sessions on the router to build ownership.
        $sessions = HotspotSession::where('router_id', $routerId)
            ->where('started_at', '<=', $to)
            ->where('last_seen_at', '>=', $from->subDay())
            ->limit(10001)->get();
        if ($sessions->count() > 10000) {
            throw new RuntimeException('Demasiadas sesiones; reduce el intervalo.');
        }

        $unionRows = [];
        foreach ($sessions as $session) {
            if (! filter_var($session->ip_address, FILTER_VALIDATE_IP)) {
                continue;
            }
            $upper = $session->ended_at ?? $session->last_seen_at;
            $unionRows[] = sprintf(
                "SELECT toUInt64(%d) AS session_id, '%s' AS ip, toUInt64(%d) AS sess_start, toUInt64(%d) AS sess_end, toUInt64(%d) AS owner_id",
                $session->id, $session->ip_address,
                $session->started_at->getTimestampMs(), $upper->getTimestampMs(), $session->hotspot_user_id
            );
        }

        if (! $unionRows) {
            return ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'sites' => []];
        }

        $ownershipValues = implode("\n  UNION ALL ", $unionRows);

        $rows = $this->clickhouse->query(<<<SQL
SELECT
    destination_ip,
    sum(flow_bytes)                            AS total_bytes,
    sumIf(flow_bytes, direction = 'upload')   AS upload_bytes,
    sumIf(flow_bytes, direction = 'download') AS download_bytes,
    count()                                    AS flows,
    countDistinct(sole_owner)                  AS unique_users,
    sumIf(flow_end - flow_start, tq = 'exporter') AS duration_ms
FROM (
    SELECT
        destination_ip,
        direction,
        tq,
        flow_bytes,
        flow_start,
        flow_end,
        owner_count,
        sole_owner,
        sampling_rate
    FROM (
        SELECT
            f.start_ms        AS flow_start,
            f.end_ms          AS flow_end,
            f.time_quality    AS tq,
            f.direction,
            f.sampling_rate,
            f.destination_ip,
            f.bytes           AS flow_bytes,
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
        GROUP BY
            f.start_ms, f.end_ms, f.time_quality, f.direction, f.sampling_rate,
            f.destination_ip, f.bytes
    )
    -- Only count flows that were unambiguously attributed to exactly one user.
    WHERE owner_count = 1
      AND sole_sess_start <= flow_start
      AND sole_sess_end   >= flow_end
      AND tq = 'exporter'
      AND direction != 'unknown'
      AND sampling_rate = 1
)
GROUP BY destination_ip
ORDER BY total_bytes DESC
LIMIT 200
SQL, [
            'exporter'     => config('traffic.exporter'),
            'from_seconds' => $from->timestamp,
            'to_seconds'   => $to->timestamp,
            'from_ms'      => $from->getTimestampMs(),
            'to_ms'        => $to->getTimestampMs(),
        ]);

        $sites = [];
        foreach ($rows as $row) {
            $sites[$row['destination_ip']] = [
                'ip'             => $row['destination_ip'],
                'total_bytes'    => (int) $row['total_bytes'],
                'upload_bytes'   => (int) $row['upload_bytes'],
                'download_bytes' => (int) $row['download_bytes'],
                'flows'          => (int) $row['flows'],
                'unique_users'   => (int) $row['unique_users'],
                'duration_ms'    => (int) $row['duration_ms'],
                'ptr'            => null,
                'dns_status'     => 'pending',
            ];
        }
        $this->enrichDns($sites);

        return [
            'from'  => $from->toIso8601String(),
            'to'    => $to->toIso8601String(),
            'sites' => array_values($sites),
        ];
    }

    // -------------------------------------------------------------------------

    private function enrichDns(array &$items): void
    {
        if (! $items) {
            return;
        }
        $ips = array_filter(array_keys($items), fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP));
        if (! $ips) {
            return;
        }
        $dnsRows = $this->clickhouse->query(
            'SELECT ip, ptr, status FROM traffic.dns FINAL WHERE expires_at > now() AND ip IN {ips:Array(String)}',
            ['ips' => '['.implode(',', array_map(fn ($ip) => "'".$ip."'", $ips)).']']
        );
        foreach ($dnsRows as $dns) {
            if (isset($items[$dns['ip']])) {
                $items[$dns['ip']]['ptr']        = $dns['ptr'] ?: null;
                $items[$dns['ip']]['dns_status'] = $dns['status'];
            }
        }
    }

    private function emptyUserReport(HotspotUser $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'user'           => ['id' => $user->id, 'username' => $user->username],
            'from'           => $from->toIso8601String(),
            'to'             => $to->toIso8601String(),
            'total_bytes'    => 0,
            'upload_bytes'   => 0,
            'download_bytes' => 0,
            'destinations'   => [],
        ];
    }
}
