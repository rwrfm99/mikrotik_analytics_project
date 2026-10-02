import os
import tempfile
import unittest
from unittest.mock import patch, Mock

os.environ['SPOOL_DIR'] = tempfile.mkdtemp()
from service import resolve
import dns.resolver


class DnsTest(unittest.TestCase):
    @patch('service.dns.resolver.Resolver')
    def test_ptr_retains_ttl(self, factory):
        answer = Mock()
        answer.rrset.ttl = 123
        answer.__iter__ = Mock(return_value=iter([Mock(target='dns.example.')]))
        factory.return_value.resolve.return_value = answer
        self.assertEqual(resolve('8.8.8.8'), ('dns.example', 'resolved', 123))

    @patch('service.dns.resolver.Resolver')
    def test_negative_cache(self, factory):
        factory.return_value.resolve.side_effect = dns.resolver.NXDOMAIN()
        self.assertEqual(resolve('8.8.8.8'), ('', 'not_found', 300))

    @patch('service.dns.resolver.Resolver')
    def test_private_ips_do_not_leave_resolver(self, factory):
        self.assertEqual(resolve('192.168.9.10'), ('', 'private', 3600))
        factory.assert_not_called()


if __name__ == '__main__':
    unittest.main()
