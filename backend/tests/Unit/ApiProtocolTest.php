<?php

namespace Tests\Unit;

use App\Services\Mikrotik\ApiException;
use App\Services\Mikrotik\ApiProtocol;
use PHPUnit\Framework\TestCase;

class ApiProtocolTest extends TestCase
{
    private function protocol(string $data): ApiProtocol
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $data);
        rewind($stream);

        return new ApiProtocol($stream, microtime(true) + 5);
    }

    private function sentence(array $words): string
    {
        return implode('', array_map(fn ($word) => ApiProtocol::encodeLength(strlen($word)).$word, $words))."\0";
    }

    public function test_lengths_at_encoding_boundaries(): void
    {
        $this->assertSame('7f', bin2hex(ApiProtocol::encodeLength(127)));
        $this->assertSame('8080', bin2hex(ApiProtocol::encodeLength(128)));
        $this->assertSame('bfff', bin2hex(ApiProtocol::encodeLength(16383)));
        $this->assertSame('c04000', bin2hex(ApiProtocol::encodeLength(16384)));
    }

    public function test_reply_preserves_equal_signs_and_handles_long_words(): void
    {
        foreach ([20, 128, 16384] as $length) {
            $value = 'name='.str_repeat('a', $length);
            $protocol = $this->protocol($this->sentence(['!re', '=user='.$value]).$this->sentence(['!done']));
            $this->assertSame([['user' => $value]], $protocol->readReply());
        }
    }

    public function test_empty_snapshot_is_successful(): void
    {
        $this->assertSame([], $this->protocol($this->sentence(['!done']))->readReply());
    }

    public function test_partial_response_is_never_returned_as_a_complete_snapshot(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('connection_closed');
        $this->protocol($this->sentence(['!re', '=user=example']))->readReply();
    }

    public function test_router_error_is_redacted(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('request_rejected');
        $this->protocol($this->sentence(['!trap', '=message=secret-value']))->readReply();
    }

    public function test_oversized_word_is_rejected_before_allocation(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('protocol_limit');
        $this->protocol("\xF0\x10\x00\x00\x00")->readReply();
    }

    public function test_sentence_writer_terminates_and_encodes_utf8_bytes(): void
    {
        $stream = fopen('php://memory', 'r+');
        $protocol = new ApiProtocol($stream, microtime(true) + 5);
        $protocol->writeSentence(['/login', '=name=josé']);
        rewind($stream);
        $this->assertSame($this->sentence(['/login', '=name=josé']), stream_get_contents($stream));
    }
}
