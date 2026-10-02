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
    con.execute('CREATE TABLE IF NOT EXISTS names (ip TEXT PRIMARY KEY, due REAL NOT NULL DEFAULT 0)')


def stamp(value):
    return dt.datetime.fromtimestamp(value, dt.timezone.utc).strftime('%Y-%m-%d %H:%M:%S.%f')[:23]


def insert(table, rows):
    body = ('INSERT INTO traffic.' + table + ' FORMAT JSONEachRow\n' + '\n'.join(json.dumps(r) for r in rows)).encode()
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
                    state.update(last_insert=time.time(), inserted=state['inserted']+len(batch))
                state['storage_error'] = False
        except Exception:
            state['storage_error'] = True
        time.sleep(1)


def resolve(ip):
    if not ipaddress.ip_address(ip).is_global:
        return '', 'private', 3600
    resolver = dns.resolver.Resolver()
    if os.getenv('DNS_NAMESERVERS'):
        resolver.nameservers = [str(ipaddress.ip_address(value.strip())) for value in os.environ['DNS_NAMESERVERS'].split(',')]
    resolver.timeout = 2
    resolver.lifetime = 3
    try:
        result = resolver.resolve(dns.reversename.from_address(ip), 'PTR')
        names = sorted(str(r.target).rstrip('.') for r in result)
        return ', '.join(names)[:1024], 'resolved', max(1, min(result.rrset.ttl, 86400))
    except (dns.resolver.NXDOMAIN, dns.resolver.NoAnswer):
        return '', 'not_found', 300
    except Exception:
        return '', 'error', 60


def dns_loop():
    while True:
        try:
            with connect() as con:
                row = con.execute('SELECT ip FROM names WHERE due < ? ORDER BY due LIMIT 1', (time.time(),)).fetchone()
            if not row:
                time.sleep(2)
                continue
            ip = row[0]
            ptr, status, ttl = resolve(ip)
            now = time.time()
            insert('dns', [dict(ip=ip, ptr=ptr, status=status,
                                checked_at=stamp(now)[:19], expires_at=stamp(now+ttl)[:19])])
            with connect() as con:
                con.execute('UPDATE names SET due=? WHERE ip=?', (now+ttl, ip))
        except Exception:
            time.sleep(5)
        time.sleep(.2)


class Health(BaseHTTPRequestHandler):
    def do_GET(self):
        with connect() as con:
            pending = con.execute('SELECT count(*) FROM spool').fetchone()[0]
        payload = json.dumps(dict(state, pending=pending, decoder=dict(decoder.stats))).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, *_):
        pass


def main():
    allowed = {ip.strip() for ip in os.getenv('IPFIX_EXPORTERS', os.getenv('MIKROTIK_HOST', '')).split(',') if ip.strip()}
    if os.getenv('IPFIX_TRUSTED_RELAY'):
        allowed.add(os.environ['IPFIX_TRUSTED_RELAY'])
    threading.Thread(target=flush_loop, daemon=True).start()
    threading.Thread(target=dns_loop, daemon=True).start()
    threading.Thread(target=ThreadingHTTPServer(('0.0.0.0', 9001), Health).serve_forever, daemon=True).start()
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
            # Explicit single-exporter relay mapping for Docker Desktop's UDP proxy.
            logical_peer = (os.getenv('IPFIX_EXPORTER', os.getenv('MIKROTIK_HOST')), peer[1]) if peer[0] == os.getenv('IPFIX_TRUSTED_RELAY') else peer
            rows = decoder.decode(packet, logical_peer)
            with connect() as con:
                pending = con.execute('SELECT count(*) FROM spool').fetchone()[0]
                if pending + len(rows) > 500000:
                    state['dropped'] += len(rows)
                    continue
                for row in rows:
                    row['received_at'] = stamp(row['received_at'])
                    row['flow_time'] = stamp(row['flow_time'])[:19]
                    con.execute('INSERT OR IGNORE INTO spool VALUES (?,?)', (row['event_id'], json.dumps(row)))
                    if row['destination_ip']:
                        con.execute('INSERT OR IGNORE INTO names(ip) VALUES (?)', (row['destination_ip'],))
        except (DecodeError, ValueError, IndexError):
            state['malformed'] += 1
        except Exception:
            state['storage_error'] = True
            state['dropped'] += len(rows) if 'rows' in locals() else 0


if __name__ == '__main__':
    main()
