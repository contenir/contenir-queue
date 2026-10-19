<?php

declare(strict_types=1);

namespace Contenir\Queue\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Time since it was started, to the microsecond, on any PSR-20 clock.
 *
 * @internal
 */
final readonly class Stopwatch
{
    public DateTimeImmutable $startedAt;

    public function __construct(
        private ClockInterface $clock,
    ) {
        $this->startedAt = $clock->now();
    }

    /**
     * Whole seconds and microseconds are subtracted apart: as one float, a
     * time since 1970 cannot hold its microseconds exactly.
     */
    public function elapsed(): float
    {
        $now = $this->clock->now();

        return (
            $now->getTimestamp()
            - $this->startedAt->getTimestamp()
                + (((int) $now->format('u') - (int) $this->startedAt->format('u')) / 1_000_000)
        );
    }
}
