<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Worker;

use Contenir\Queue\Worker\DrainOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class DrainOptionsTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidProvider(): array
    {
        return [
            'negative jobs'        => [['maxJobs' => -1]],
            'negative time'        => [['maxSeconds' => -0.1]],
            'negative heartbeat'   => [['skipWhenHeartbeatWithin' => -1]],
            'stale under a second' => [['staleAfterSeconds' => 0]],
        ];
    }

    #[Test]
    public function defaultsAreAFewJobsInAFewSeconds(): void
    {
        $options = new DrainOptions();

        static::assertSame(
            [3, 2.0, 'default', 120, 3_600],
            [
                $options->maxJobs,
                $options->maxSeconds,
                $options->workerName,
                $options->skipWhenHeartbeatWithin,
                $options->staleAfterSeconds,
            ],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('invalidProvider')]
    public function refusesNegativeLimits(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Drain limits cannot be negative, and the stale time must be at least one second',
        );

        new DrainOptions(...$arguments);
    }

    #[Test]
    public function theSmallestValuesAreAccepted(): void
    {
        static::assertSame(1, (new DrainOptions(0, 0.0, 'w', 0, 1))->staleAfterSeconds);
    }
}
