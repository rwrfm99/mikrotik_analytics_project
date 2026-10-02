<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Services\Traffic\ClickHouseClient;
use App\Services\Traffic\TrafficRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrafficTest extends TestCase
{
    use RefreshDatabase;

    public function test_clickhouse_errors_hide_credentials(): void
    {
        config(['traffic.password' => 'secret-value']);
        Http::fake(['*' => Http::response('secret-value', 403)]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ClickHouse no disponible');
        (new ClickHouseClient)->query('SELECT 1');
    }

    public function test_write_queries_are_rejected_without_network(): void
    {
        Http::fake();
        try {
            (new ClickHouseClient)->query('DROP TABLE traffic.flows');
            $this->fail('Write query accepted');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Consulta no permitida.', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_report_rejects_excessive_range_before_clickhouse_access(): void
    {
        config(['mikrotik.host' => '192.0.2.1']);
        $router = Router::create(['host' => '192.0.2.1', 'name' => 'Test']);
        $user = $router->users()->create(['username' => 'test']);
        Http::fake();
        $this->getJson('/api/v1/traffic/users/'.$user->id.'?'.http_build_query([
            'from' => now()->subDays(2)->toIso8601String(), 'to' => now()->toIso8601String(),
        ]))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_real_clickhouse_correlation_with_isolated_inline_fixtures(): void
    {
        if (! getenv('TRAFFIC_SQL_TEST')) {
            $this->markTestSkipped('Set TRAFFIC_SQL_TEST=1 to test read-only SQL against local ClickHouse.');
        }
        config(['traffic.exporter' => '192.0.2.1']);
        $router = Router::create(['host' => '192.0.2.1', 'name' => 'Test']);
        $alice = $router->users()->create(['username' => 'alice']);
        $bob = $router->users()->create(['username' => 'bob']);
        $other = $router->users()->create(['username' => 'other']);
        foreach ([[$alice, '10:00', '11:00'], [$bob, '11:00', '12:00'], [$other, '10:30', '10:45']] as [$user,$start,$end]) {
            $user->sessions()->create(['router_id' => $router->id, 'username' => $user->username,
                'ip_address' => '192.168.9.10', 'mac_address' => 'AA:BB:CC:DD:EE:01',
                'started_at' => '2026-10-02 '.$start.':00', 'last_seen_at' => '2026-10-02 '.$end.':00',
                'ended_at' => '2026-10-02 '.$end.':00']);
        }
        $fixtures = [];
        foreach ([['10:00:10', 1000, '192.168.9.10'], ['11:00:10', 2000, '192.168.9.10'], ['10:35:10', 3000, '192.168.9.10'], ['10:10:00', 4000, '192.168.9.99']] as [$time,$bytes,$ip]) {
            $ms = CarbonImmutable::parse('2026-10-02 '.$time, 'UTC')->getTimestampMs();
            $fixtures[] = "('192.0.2.1','2026-10-02 11:59:00',$ms,".($ms + 1000).",'$ip','exporter','upload',1,'8.8.8.8',6,$bytes,1)";
        }
        $source = "values('exporter String, flow_time DateTime, start_ms UInt64, end_ms UInt64, client_ip String, time_quality String, direction String, sampling_rate UInt32, destination_ip String, protocol UInt8, bytes UInt64, packets UInt64',".implode(',', $fixtures).')';
        $client = new class($source) extends ClickHouseClient
        {
            public function __construct(private string $source) {}

            public function query(string $sql, array $parameters = []): array
            {
                if (str_contains($sql, 'traffic.dns')) {
                    return [];
                }

                // Inline VALUES: no writes, no production flow/session data read.
                return parent::query(str_replace('traffic.flows FINAL', $this->source, $sql), $parameters);
            }
        };
        $repo = new TrafficRepository($client);
        $from = CarbonImmutable::parse('2026-10-02 10:00', 'UTC');
        $to = $from->addHours(2);
        $a = $repo->report($alice, $from, $to);
        $b = $repo->report($bob, $from, $to);
        $this->assertSame(1000, $a['upload_bytes']);
        $this->assertSame(2000, $b['upload_bytes']);
        $this->assertSame(10000, $a['quality']['total_bytes']);
        $this->assertSame(3000, $a['quality']['ambiguous_bytes']);
        $this->assertSame(4000, $a['quality']['unknown_bytes']);
        $this->assertSame(30.0, $a['quality']['coverage_percent']);
    }
}
