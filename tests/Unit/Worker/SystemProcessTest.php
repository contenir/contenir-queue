<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Worker;

use Contenir\Queue\Worker\SystemProcess;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function hrtime;
use function memory_get_usage;

#[Group('unit')]
#[Group('slow')]
final class SystemProcessTest extends TestCase
{
    #[Test]
    public function aNegativeSleepReturnsAtOnce(): void
    {
        $start = hrtime(as_number: true);
        (new SystemProcess())->sleep(-5);

        static::assertLessThan(500_000_000, hrtime(as_number: true) - $start);
    }

    #[Test]
    public function memoryUsageIsWhatTheProcessHasFromTheSystem(): void
    {
        static::assertSame(memory_get_usage(real_usage: true), (new SystemProcess())->memoryUsage());
    }

    #[Test]
    public function sleepsForTheGivenSeconds(): void
    {
        $start = hrtime(as_number: true);
        (new SystemProcess())->sleep(1);

        static::assertGreaterThanOrEqual(900_000_000, hrtime(as_number: true) - $start);
    }
}
