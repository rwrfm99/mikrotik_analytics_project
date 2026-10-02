<?php

namespace Tests\Unit;

use App\Services\Mikrotik\ApiException;
use App\Services\Mikrotik\RouterDuration;
use PHPUnit\Framework\TestCase;

class RouterDurationTest extends TestCase
{
    public function test_routeros_duration_formats(): void
    {
        $this->assertSame(0, RouterDuration::seconds('0s'));
        $this->assertSame(93784, RouterDuration::seconds('1d2h3m4s'));
        $this->assertSame(93784, RouterDuration::seconds('1d02:03:04'));
        $this->assertSame(698584, RouterDuration::seconds('1w1d02:03:04'));
    }

    public function test_invalid_duration_fails_instead_of_resetting_uptime(): void
    {
        $this->expectException(ApiException::class);
        RouterDuration::seconds('invalid');
    }
}
