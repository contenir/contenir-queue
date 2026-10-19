<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Clock;

use Contenir\Queue\Clock\SystemClock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class SystemClockTest extends TestCase
{
    #[Test]
    public function isTheCurrentTimeInUtc(): void
    {
        $before = new DateTimeImmutable();
        $now    = (new SystemClock())->now();
        $after  = new DateTimeImmutable();

        static::assertSame(
            ['UTC', true],
            [$now->getTimezone()->getName(), $before <= $now && $now <= $after],
        );
    }
}
