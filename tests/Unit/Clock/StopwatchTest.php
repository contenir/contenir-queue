<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Clock;

use Contenir\Queue\Clock\Stopwatch;
use Contenir\Queue\Tests\TestAsset\Clock\MutableClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class StopwatchTest extends TestCase
{
    #[Test]
    public function elapsedIsExactToTheMicrosecond(): void
    {
        $clock     = new MutableClock('2026-10-19 09:00:00.75');
        $stopwatch = new Stopwatch($clock);
        $clock->set('2026-10-19 09:00:02.55');

        static::assertSame(1.8, $stopwatch->elapsed());
    }

    #[Test]
    public function startsAtTheClocksTime(): void
    {
        $clock = new MutableClock('2026-10-19 09:00:00.25');

        static::assertSame('09:00:00.250000', (new Stopwatch($clock))->startedAt->format('H:i:s.u'));
    }
}
