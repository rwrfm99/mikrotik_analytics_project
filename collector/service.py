import base64
import datetime as dt
import ipaddress
import json
import os
import socket
import sqlite3
import threading
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import dns.resolver
import dns.reversename
from ipfix import Decoder, DecodeError

ROOT = os.getenv('SPOOL_DIR', '/data')
os.makedirs(ROOT, exist_ok=True)
state = dict(status='starting', last_packet=None, last_insert=None, inserted=0,
             rejected_exporters=0, malformed=0, dropped=0, storage_error=False)
decoder = Decoder(os.getenv('MIKROTIK_LOCAL_NETWORK', '192.168.8.0/22'))


def connect():
    con = sqlite3.connect(ROOT + '/spool.sqlite', timeout=10)
    con.execute('PRAGMA journal_mode=WAL')
    return con


with connect() as con:
    con.execute('CREATE TABLE IF NOT EXISTS spool (id TEXT PRIMARY KEY, body TEXT NOT NULL)')
    # fail_count tracks consecutive DNS errors for exponential backoff
    con.execute('''CREATE TABLE IF NOT EXISTS names (
        ip         TEXT PRIMARY KEY,
        due        REAL NOT NULL DEFAULT 0,
        fail_count INTEGER NOT NULL DEFAULT 0
    )''')
    # Add fail_count column if upgrading from older schema without it
    try:
        con.execute('ALTER TABLE names ADD COLUMN fail_count INTEGER NOT NULL DEFAULT 0')
    except Exception:
        pass


def stamp(value):
    return dt.datetime.fromtimestamp(value, dt.timezone.utc).strftime('%Y-%m-%d %H:%M:%S.%f')[:23]


def insert(table, rows):
    body = ('INSERT INTO traffic.' + table + ' FORMAT JSONEachRow\n'
            + '\n'.join(json.dumps(r) for r in rows)).encode()
    request = urllib.request.Request('http://clickhouse:8123/', data=body, method='POST')
    credentials = 'traffic_ingest:' + os.environ['CLICKHOUSE_PASSWORD']
    request.add_header('Authorization', 'Basic ' + base64.b64encode(credentials.encode()).decode())
    with urllib.request.urlopen(request, timeout=10) as response:
        response.read()


def flush_loop():
    while True:
        try:
            with connect() as con:
                batch = con.execute('SELECT id,body FROM spool LIMIT 500').fetchall()
                if batch:
                    insert('flows', [json.loads(body) for _, body in batch])
                    con.executemany('DELETE FROM spool WHERE id=?', [(key,) for key, _ in batch])
                    state.update(last_insert=time.time(), inserted=state['inserted'] + len(batch))
                state['storage_error'] = False
        except Exception:
            state['storage_error'] = True
        time.sleep(1)


# ---------------------------------------------------------------------------
# DNS resolution with smarter TTLs
# ---------------------------------------------------------------------------

# Backoff TTLs for consecutive errors: 1st→5min, 2nd→30min, 3rd+→2h
_ERROR_BACKOFF = [300, 1800, 7200]


def resolve(ip):
    """Return (ptr, status, ttl_seconds) for a given IP."""
    try:
        addr = ipaddress.ip_address(ip)
    except ValueError:
        return '', 'error', 3600

    # Private/loopback/link-local addresses never have PTR — cache for 24h.
    if not addr.is_global:
        return '', 'private', 86400

    resolver = dns.resolver.Resolver()
    if os.getenv('DNS_NAMESERVERS'):
        resolver.nameservers = [
            str(ipaddress.ip_address(v.strip()))
            for v in os.environ['DNS_NAMESERVERS'].split(',')
            if v.strip()
        ]
    resolver.timeout = 2
    resolver.lifetime = 3

    try:
        result = resolver.resolve(dns.reversename.from_address(ip), 'PTR')
        names = sorted(str(r.target).rstrip('.') for r in result)
        ttl = max(300, min(int(result.rrset.ttl), 86400))
        return ', '.join(names)[:1024], 'resolved', ttl
    except (dns.resolver.NXDOMAIN, dns.resolver.NoAnswer):
        # No PTR record exists — retry in 1h (not 5 min, PTR records rarely appear)
        return '', 'not_found', 3600
    except Exception:
        # Transient error — caller will apply exponential backoff
        return '', 'error', 0


def dns_loop():
    """
    Resolve pending IPs concurrently.
    - DNS_WORKERS (default 30): parallel resolution threads per batch.
    - IPs with errors get exponential backoff (5m → 30m → 2h).
    - IPs with no PTR are retried after 1h instead of 5 min.
    - Private IPs are cached for 24h to stop them clogging the queue.
    """
    workers = max(1, int(os.getenv('DNS_WORKERS', '30')))

    def resolve_one(ip, fail_count):
        ptr, status, ttl = resolve(ip)
        now = time.time()

        # Apply exponential backoff for errors
        if status == 'error':
            ttl = _ERROR_BACKOFF[min(fail_count, len(_ERROR_BACKOFF) - 1)]

        try:
            insert('dns', [dict(
                ip=ip, ptr=ptr, status=status,
                checked_at=stamp(now)[:19],
                expires_at=stamp(now + ttl)[:19],
            )])
        except Exception:
            # ClickHouse unavailable — reset due to 60s so we retry soon
            try:
                with connect() as con:
                    con.execute(
                        'UPDATE names SET due=?, fail_count=fail_count+1 WHERE ip=?',
                        (now + 60, ip),
                    )
            except Exception:
                pass
            return

        try:
            with connect() as con:
                if status == 'error':
                    con.execute(
                        'UPDATE names SET due=?, fail_count=fail_count+1 WHERE ip=?',
                        (now + ttl, ip),
                    )
                else:
                    # Reset error counter on success or not_found
                    con.execute(
                        'UPDATE names SET due=?, fail_count=0 WHERE ip=?',
                        (now + ttl, ip),
                    )
        except Exception:
            pass

    while True:
        try:
            with connect() as con:
                rows = con.execute(
                    'SELECT ip, fail_count FROM names WHERE due < ? ORDER BY due LIMIT ?',
                    (time.time(), workers),
                ).fetchall()
            if not rows:
                time.sleep(2)
                continue

            # Mark as in-progress (due = far future) before spawning threads
            # so a second loop iteration doesn't pick the same IPs.
            future = time.time() + 3600
            with connect() as con:
                con.executemany(
                    'UPDATE names SET due=? WHERE ip=?',
                    [(future, r[0]) for r in rows],
                )

            threads = [
                threading.Thread(target=resolve_one, args=(r[0], r[1]), daemon=True)
                for r in rows
            ]
            for t in threads:
                t.start()
            for t in threads:
                t.join(timeout=10)

        except Exception:
            time.sleep(5)
        time.sleep(0.05)   # tighter loop when queue is large


class Health(BaseHTTPRequestHandler):
    def do_GET(self):
        with connect() as con:
            pending  = con.execute('SELECT count(*) FROM spool').fetchone()[0]
            dns_pending = con.execute(
                'SELECT count(*) FROM names WHERE due < ?', (time.time(),)
            ).fetchone()[0]
        payload = json.dumps(dict(
            state,
            pending=pending,
            dns_pending=dns_pending,
            decoder=dict(decoder.stats),
        )).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, *_):
        pass


def main():
    allowed = {
        ip.strip()
        for ip in os.getenv('IPFIX_EXPORTERS', os.getenv('MIKROTIK_HOST', '')).split(',')
        if ip.strip()
    }
    if os.getenv('IPFIX_TRUSTED_RELAY'):
        allowed.add(os.environ['IPFIX_TRUSTED_RELAY'])

    threading.Thread(target=flush_loop, daemon=True).start()
    threading.Thread(target=dns_loop, daemon=True).start()
    threading.Thread(
        target=ThreadingHTTPServer(('0.0.0.0', 9001), Health).serve_forever,
        daemon=True,
    ).start()

    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 4194304)
    sock.bind(('0.0.0.0', 2055))
    state['status'] = 'listening'
    print(json.dumps({'status': 'listening', 'udp_port': 2055}), flush=True)

    while True:
        packet, peer = sock.recvfrom(65535)
        if peer[0] not in allowed:
            state['rejected_exporters'] += 1
            continue
        state['last_packet'] = time.time()
        try:
            logical_peer = (
                (os.getenv('IPFIX_EXPORTER', os.getenv('MIKROTIK_HOST')), peer[1])
                if peer[0] == os.getenv('IPFIX_TRUSTED_RELAY')
                else peer
            )
            rows = decoder.decode(packet, logical_peer)
            with connect() as con:
                pending = con.execute('SELECT count(*) FROM spool').fetchone()[0]
                if pending + len(rows) > 500000:
                    state['dropped'] += len(rows)
                    continue
                for row in rows:
                    row['received_at'] = stamp(row['received_at'])
                    row['flow_time']   = stamp(row['flow_time'])[:19]
                    con.execute(
                        'INSERT OR IGNORE INTO spool VALUES (?,?)',
                        (row['event_id'], json.dumps(row)),
                    )
                    # Only queue global IPs — private ones resolve instantly as 'private'
                    if row['destination_ip']:
                        try:
                            if ipaddress.ip_address(row['destination_ip']).is_global:
                                con.execute(
                                    'INSERT OR IGNORE INTO names(ip) VALUES (?)',
                                    (row['destination_ip'],),
                                )
                        except ValueError:
                            pass
        except (DecodeError, ValueError, IndexError):
            state['malformed'] += 1
        except Exception:
            state['storage_error'] = True
            state['dropped'] += len(rows) if 'rows' in locals() else 0


if __name__ == '__main__':
    main()
