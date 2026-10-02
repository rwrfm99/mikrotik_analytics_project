"""Bounded IPFIX v10 decoder. No packet payload, no proprietary analytics service."""
import hashlib
import ipaddress
import struct
import time
from collections import Counter


class DecodeError(ValueError):
    pass


class Decoder:
    def __init__(self, network, template_ttl=1800):
        self.network = ipaddress.ip_network(network)
        self.templates = {}
        self.ttl = template_ttl
        self.stats = Counter()

    def decode(self, data, peer, now=None):
        now = now if now is not None else time.time()
        if len(data) < 16:
            raise DecodeError('short_header')
        version, length, exported, sequence, domain = struct.unpack('!HHIII', data[:16])
        if version != 10 or length != len(data):
            raise DecodeError('invalid_header_or_version')
        self.templates = {k: v for k, v in self.templates.items() if now - v[0] < self.ttl}
        rows, offset, index = [], 16, 0
        while offset < length:
            if offset + 4 > length:
                raise DecodeError('short_set')
            set_id, set_len = struct.unpack('!HH', data[offset:offset+4])
            if set_len < 4 or offset + set_len > length:
                raise DecodeError('bad_set_length')
            body = data[offset+4:offset+set_len]
            offset += set_len
            if set_id in (2, 3):
                pos = 0
                header_size = 6 if set_id == 3 else 4
                while len(body) - pos >= header_size:
                    tid, count = struct.unpack('!HH', body[pos:pos+4])
                    pos += header_size
                    if tid < 256 or count > 128:
                        raise DecodeError('invalid_template')
                    key = (peer, domain, tid)
                    if count == 0:
                        self.templates.pop(key, None)
                        continue
                    fields = []
                    for _ in range(count):
                        if pos + 4 > len(body):
                            raise DecodeError('short_template')
                        element, size = struct.unpack('!HH', body[pos:pos+4])
                        pos += 4
                        pen = 0
                        if element & 0x8000:
                            if pos + 4 > len(body):
                                raise DecodeError('short_enterprise')
                            pen = struct.unpack('!I', body[pos:pos+4])[0]
                            pos += 4
                        if size == 0:
                            raise DecodeError('zero_field')
                        fields.append((pen, element & 0x7fff, size))
                    if len(self.templates) >= 4096 and key not in self.templates:
                        raise DecodeError('template_limit')
                    self.templates[key] = (now, fields, set_id == 3)
                if any(body[pos:]):
                    raise DecodeError('invalid_padding')
            elif set_id >= 256:
                template = self.templates.get((peer, domain, set_id))
                if not template:
                    self.stats['missing_template_sets'] += 1
                    continue
                _, fields, options = template
                minimum = sum(1 if f[2] == 65535 else f[2] for f in fields)
                pos = 0
                while len(body) - pos >= minimum:
                    record = {}
                    for pen, element, size in fields:
                        if size == 65535:
                            size = body[pos]
                            pos += 1
                            if size == 255:
                                if pos + 2 > len(body):
                                    raise DecodeError('short_variable_length')
                                size = struct.unpack('!H', body[pos:pos+2])[0]
                                pos += 2
                        if pos + size > len(body):
                            raise DecodeError('short_record')
                        if pen == 0:
                            record[element] = body[pos:pos+size]
                        pos += size
                    if not options:
                        event_id = hashlib.sha256(peer[0].encode() + str(peer[1]).encode() + data + str(index).encode()).hexdigest()
                        row = self.normalize(record, exported, now, peer[0], event_id)
                        if row:
                            rows.append(row)
                    index += 1
                if any(body[pos:]):
                    raise DecodeError('invalid_data_padding')
        return rows

    def normalize(self, fields, exported, now, exporter, event_id):
        def number(key, default=0):
            return int.from_bytes(fields[key], 'big') if key in fields else default

        def address(*keys):
            for key in keys:
                if key in fields:
                    if len(fields[key]) not in (4, 16):
                        raise DecodeError('invalid_ip')
                    value = ipaddress.ip_address(fields[key])
                    if not value.is_unspecified:
                        return str(value)
            return ''

        # Do not sum cumulative counters as delta counters. NAT events aren't flows.
        if 1 not in fields or 2 not in fields:
            self.stats['unsupported_counter_records'] += 1
            return None
        src, dst = address(8, 27), address(12, 28)
        src_nat, dst_nat = address(225, 281), address(226, 282)
        local_src = {ip for ip in (src, src_nat) if ip and ipaddress.ip_address(ip) in self.network}
        local_dst = {ip for ip in (dst, dst_nat) if ip and ipaddress.ip_address(ip) in self.network}
        client, destination, direction = '', '', 'unknown'
        if len(local_src) == 1 and not local_dst:
            client, direction = next(iter(local_src)), 'upload'
            destination = dst_nat if dst_nat else dst
        elif len(local_dst) == 1 and not local_src:
            client, direction = next(iter(local_dst)), 'download'
            destination = src_nat if src_nat else src
        start, end, quality = exported * 1000, exported * 1000, 'export_time'
        if 152 in fields and 153 in fields:
            start, end, quality = number(152), number(153), 'exporter'
        elif 150 in fields and 151 in fields:
            start, end, quality = number(150)*1000, number(151)*1000, 'exporter'
        elif 160 in fields and 22 in fields and 21 in fields:
            start, end, quality = number(160)+number(22), number(160)+number(21), 'exporter'
        if end < start or start < 946684800000 or end > (now+300)*1000:
            start, end, quality = exported*1000, exported*1000, 'invalid_time'
        if exported < 946684800 or exported > now + 300:
            raise DecodeError('invalid_export_time')
        sampling = number(34, number(305, 1))
        if sampling < 1 or sampling > 1000000:
            raise DecodeError('invalid_sampling')
        # Sampling factor retained; bytes remain raw to avoid invented volume.
        return dict(event_id=event_id, exporter=exporter, received_at=now,
                    flow_time=exported, start_ms=start, end_ms=end, time_quality=quality,
                    client_ip=client, destination_ip=destination, direction=direction,
                    src_ip=src, dst_ip=dst, src_nat=src_nat, dst_nat=dst_nat,
                    src_port=number(7), dst_port=number(11), protocol=number(4),
                    bytes=number(1), packets=number(2), sampling_rate=sampling)
