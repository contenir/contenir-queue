<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Monitor;

use Contenir\Queue\Monitor\QueueHealth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class QueueHealthTest extends TestCase
{
    /**
     * @return array<string, array{?int, bool, bool}>
     */
    public static function heartbeatProvider(): array
    {
        return [
            'never ran'             => [null, false, true],
            'just now'              => [0, true, false],
            'ten minutes ago'       => [600, true, false],
            'just over ten minutes' => [601, false, false],
            'an hour ago'           => [3_600, false, false],
            'just over an hour ago' => [3_601, false, true],
        ];
    }

    #[Test]
    #[DataProvider('heartbeatProvider')]
    public function judgesTheHeartbeat(?int $age, bool $configured, bool $stopped): void
    {
        $health = new QueueHealth(null, $age, false, 0, null, 0);

        static::assertSame([$configured, $stopped], [$health->isConfigured(), $health->isStopped()]);
    }

    #[Test]
    public function thePlansThresholdsAreTenMinutesAndAnHour(): void
    {
        static::assertSame([600, 3_600], [QueueHealth::CONFIGURED_WITHIN, QueueHealth::STOPPED_AFTER]);
    }

    #[Test]
    public function thresholdsCanBeChanged(): void
    {
        $health = new QueueHealth(null, 120, false, 0, null, 0);

        static::assertSame(
            [false, true, true, false],
            [$health->isConfigured(119), $health->isConfigured(120), $health->isStopped(119), $health->isStopped(120)],
        );
    }
}
