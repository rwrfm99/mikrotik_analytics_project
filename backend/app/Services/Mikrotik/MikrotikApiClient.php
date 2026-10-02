<?php

namespace App\Services\Mikrotik;

class MikrotikApiClient
{
    public function configured(): bool
    {
        return filled(config('mikrotik.host'))
            && filled(config('mikrotik.username'))
            && filled(config('mikrotik.password'));
    }

    public function activeUsers(): array
    {
        return $this->read('/ip/hotspot/active/print', '.id,user,address,mac-address,uptime,idle-time,session-time-left,bytes-in,bytes-out,packets-in,packets-out,login-by,server');
    }

    public function hotspotUsers(): array
    {
        return $this->read('/ip/hotspot/user/print', 'name,profile,disabled');
    }

    public function clock(): array
    {
        return $this->read('/system/clock/print', 'date,time,time-zone-name,gmt-offset');
    }

    private function read(string $command, string $properties): array
    {
        if (! $this->configured()) {
            throw new ApiException('not_configured');
        }
        $host = config('mikrotik.host');
        $port = (int) config('mikrotik.port');
        if (! is_string($host) || ! preg_match('/^[a-zA-Z0-9.:-]+$/D', $host) || $port < 1 || $port > 65535) {
            throw new ApiException('invalid_configuration');
        }
        $timeout = max(1, min(30, (int) config('mikrotik.timeout_seconds', 5)));
        $address = str_contains($host, ':') ? '['.$host.']' : $host;
        $scheme = config('mikrotik.tls') ? 'tls' : 'tcp';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $stream = @stream_socket_client($scheme.'://'.$address.':'.$port, $errorCode, $errorText, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new ApiException('connection_failed');
        }
        try {
            stream_set_timeout($stream, $timeout);
            $protocol = new ApiProtocol($stream, microtime(true) + $timeout * 3);
            $protocol->writeSentence(['/login', '=name='.config('mikrotik.username'), '=password='.config('mikrotik.password')]);
            try {
                $protocol->readReply();
            } catch (ApiException $exception) {
                throw new ApiException($exception->getMessage() === 'request_rejected' ? 'authentication_failed' : $exception->getMessage());
            }
            $protocol->writeSentence([
                $command,
                '=.proplist='.$properties,
            ]);

            return $protocol->readReply();
        } finally {
            fclose($stream);
        }
    }
}
