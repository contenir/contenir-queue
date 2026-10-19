<?php

declare(strict_types=1);

namespace Contenir\Queue\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Psr\Clock\ClockInterface;

/**
 * The real time in UTC, used when the container has no PSR-20 clock.
 *
 * @api
 */
final class SystemClock implements ClockInterface
{
    /**
     * @mago-expect analysis:unhandled-thrown-type "UTC" is always a valid time zone.
     */
    #[Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
