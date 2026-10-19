<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Psr\Clock\ClockInterface;

use function round;

/**
 * A clock that stands still until a test moves it.
 */
final class MutableClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-10-19 09:00:00')
    {
        $this->now = new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }

    public function advance(int|float $seconds): void
    {
        $microseconds = (int) round($seconds * 1_000_000);
        $this->now    = $this->now->modify("+{$microseconds} usec");
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(string $now): void
    {
        $this->now = new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }
}
