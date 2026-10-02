import ipaddress
import struct
import unittest
from ipfix import Decoder, DecodeError

NOW = 1790964000
PEER = ('192.0.2.1', 9000)


def message(sets, sequence=0, domain=1):
    body = b''.join(struct.pack('!HH', sid, len(data)+4)+data for sid, data in sets)
    return struct.pack('!HHIII', 10, len(body)+16, NOW, sequence, domain)+body


def template(fields, tid=256):
    return struct.pack('!HH', tid, len(fields))+b''.join(struct.pack('!HH', *field) for field in fields)


def ipv4(value):
    return ipaddress.ip_address(value).packed


class DecoderTest(unittest.TestCase):
    def setUp(self):
        self.decoder = Decoder('192.168.8.0/22')

    def fields(self, changes=None):
        fields = {8: ipv4('192.168.9.10'), 12: ipv4('8.8.8.8'), 1: (1024).to_bytes(8),
                  2: (5).to_bytes(4), 152: ((NOW-5)*1000).to_bytes(8), 153: ((NOW-1)*1000).to_bytes(8)}
        fields.update(changes or {})
        return fields

    def decode_fields(self, fields):
        spec = [(key, len(value)) for key, value in fields.items()]
        return self.decoder.decode(message([(2, template(spec)), (256, b''.join(fields.values()))]), PEER, NOW)

    def test_upload_template_and_record(self):
        row = self.decode_fields(self.fields())[0]
        self.assertEqual((row['client_ip'], row['direction'], row['bytes']), ('192.168.9.10', 'upload', 1024))
        self.assertEqual(row['time_quality'], 'exporter')

    def test_nat_download(self):
        row = self.decode_fields(self.fields({8:ipv4('8.8.8.8'), 12:ipv4('192.168.1.29'), 226:ipv4('192.168.9.10')}))[0]
        self.assertEqual((row['client_ip'], row['destination_ip'], row['direction']), ('192.168.9.10', '8.8.8.8', 'download'))

    def test_source_nat_upload(self):
        row = self.decode_fields(self.fields({8:ipv4('192.168.1.29'), 225:ipv4('192.168.9.10')}))[0]
        self.assertEqual(row['direction'], 'upload')

    def test_multiple_local_addresses_are_not_guessed(self):
        row = self.decode_fields(self.fields({226:ipv4('192.168.9.11')}))[0]
        self.assertEqual(row['direction'], 'unknown')

    def test_missing_template_and_domain_isolation(self):
        packet = message([(256, b'1234')])
        self.assertEqual(self.decoder.decode(packet, PEER, NOW), [])
        self.assertEqual(self.decoder.stats['missing_template_sets'], 1)
        self.decode_fields(self.fields())
        self.assertEqual(self.decoder.decode(message([(256,b'1234')],domain=2), PEER, NOW), [])

    def test_duplicates_have_stable_event_id(self):
        first = self.decode_fields(self.fields())[0]
        second = self.decode_fields(self.fields())[0]
        self.assertEqual(first['event_id'], second['event_id'])

    def test_invalid_length_rejected(self):
        with self.assertRaises(DecodeError):
            self.decoder.decode(message([(256,b'123')])[:-1], PEER, NOW)

    def test_cumulative_counters_are_not_double_counted(self):
        fields = self.fields()
        fields[85] = fields.pop(1)
        self.assertEqual(self.decode_fields(fields), [])

    def test_missing_time_is_explicit(self):
        fields = self.fields()
        del fields[152], fields[153]
        self.assertEqual(self.decode_fields(fields)[0]['time_quality'], 'export_time')

    def test_template_expiry(self):
        self.decode_fields(self.fields())
        self.assertEqual(self.decoder.decode(message([(256,b'1234')]), PEER, NOW+1801), [])

    def test_enterprise_field_is_skipped(self):
        spec = struct.pack('!HHHHIHH',256,2,0x8008,4,999,1,4)
        result = self.decoder.decode(message([(2,spec),(256,b'12345678')]), PEER, NOW)
        self.assertEqual(result, [])


if __name__ == '__main__':
    unittest.main()
