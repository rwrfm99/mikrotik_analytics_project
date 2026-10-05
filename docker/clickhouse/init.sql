CREATE DATABASE IF NOT EXISTS traffic;
CREATE TABLE IF NOT EXISTS traffic.flows (
 event_id String, exporter String, received_at DateTime64(3, 'UTC'),
 flow_time DateTime('UTC'), start_ms UInt64, end_ms UInt64, time_quality LowCardinality(String),
 client_ip String, destination_ip String, direction LowCardinality(String),
 src_ip String, dst_ip String, src_nat String, dst_nat String,
 src_port UInt16, dst_port UInt16, protocol UInt8,
 bytes UInt64, packets UInt64, sampling_rate UInt32
) ENGINE = ReplacingMergeTree(received_at)
PARTITION BY toYYYYMMDD(flow_time)
ORDER BY (exporter, start_ms, event_id)
TTL flow_time + INTERVAL 30 DAY DELETE;

CREATE TABLE IF NOT EXISTS traffic.dns (
 ip String, ptr String, status LowCardinality(String),
 checked_at DateTime('UTC'), expires_at DateTime('UTC')
) ENGINE = ReplacingMergeTree(checked_at) ORDER BY ip
SETTINGS max_suspicious_broken_parts = 1000, min_bytes_for_wide_part = 10485760;
