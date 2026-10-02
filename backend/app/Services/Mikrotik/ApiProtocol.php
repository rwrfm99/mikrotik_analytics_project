<?php

namespace App\Services\Mikrotik;

class ApiProtocol
{
    private const MAX_WORD = 1048576;

    public static function encodeLength(int $length): string
    {
        if ($length < 0 || $length > self::MAX_WORD) {
            throw new ApiException('protocol_limit');
        }
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            return pack('n', $length | 0x8000);
        }

        return substr(pack('N', $length | 0xC00000), 1);
    }

    public function __construct(private mixed $stream, private float $deadline) {}

    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            if (microtime(true) >= $this->deadline) {
                throw new ApiException('timeout');
            }
            $chunk = @fread($this->stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $metadata = stream_get_meta_data($this->stream);
                throw new ApiException(($metadata['timed_out'] ?? false) ? 'timeout' : 'connection_closed');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function readLength(): int
    {
        $first = ord($this->readBytes(1));
        if ($first < 0x80) {
            return $first;
        }
        if ($first < 0xC0) {
            $length = (($first & 0x3F) << 8) | ord($this->readBytes(1));
        } elseif ($first < 0xE0) {
            $bytes = unpack('n', $this->readBytes(2))[1];
            $length = (($first & 0x1F) << 16) | $bytes;
        } elseif ($first < 0xF0) {
            $bytes = unpack('N', "\0".$this->readBytes(3))[1];
            $length = (($first & 0x0F) << 24) | $bytes;
        } elseif ($first === 0xF0) {
            $length = unpack('N', $this->readBytes(4))[1];
        } else {
            throw new ApiException('invalid_protocol');
        }
        if ($length > self::MAX_WORD) {
            throw new ApiException('protocol_limit');
        }

        return $length;
    }

    public function writeSentence(#[\SensitiveParameter] array $words): void
    {
        $payload = '';
        foreach ($words as $word) {
            $payload .= self::encodeLength(strlen($word)).$word;
        }
        $payload .= "\0";
        while ($payload !== '') {
            if (microtime(true) >= $this->deadline) {
                throw new ApiException('timeout');
            }
            $written = @fwrite($this->stream, $payload);
            if ($written === false || $written === 0) {
                throw new ApiException('connection_closed');
            }
            $payload = substr($payload, $written);
        }
    }

    public function readReply(): array
    {
        $rows = [];
        $totalBytes = 0;
        for ($sentences = 0; $sentences < 100001; $sentences++) {
            $words = [];
            while (($length = $this->readLength()) !== 0) {
                $totalBytes += $length;
                if (count($words) >= 100 || $totalBytes > 16777216) {
                    throw new ApiException('protocol_limit');
                }
                $words[] = $this->readBytes($length);
            }
            $type = array_shift($words);
            if ($type === '!done') {
                return $rows;
            }
            if ($type === '!trap' || $type === '!fatal') {
                // Never include the router's free-text message in logs or responses.
                throw new ApiException('request_rejected');
            }
            if ($type === null || $type === '!empty') {
                continue;
            }
            if ($type !== '!re') {
                throw new ApiException('invalid_protocol');
            }
            $row = [];
            foreach ($words as $word) {
                if (str_starts_with($word, '=')) {
                    $parts = explode('=', substr($word, 1), 2);
                    if (count($parts) === 2) {
                        $row[$parts[0]] = $parts[1];
                    }
                }
            }
            $rows[] = $row;
        }
        throw new ApiException('protocol_limit');
    }
}
