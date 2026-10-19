<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Worker;

use Contenir\Queue\Worker\WorkerStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class WorkerStatusTest extends TestCase
{
    /**
     * @return array<string, array{?string, ?string, bool}>
     */
    public static function runningProvider(): array
    {
        return [
            'locked into the future' => ['w1', '09:00:01', true],
            'locked until now'       => ['w1', '09:00:00', true],
            'lock ran out'           => ['w1', '08:59:59', false],
            'released'               => [null, null, false],
            'owner without a time'   => ['w1', null, false],
            'time without an owner'  => [null, '09:10:00', false],
        ];
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-10-19 {$time} UTC");
    }

    private static function workerStatus(
        ?string $owner = null,
        ?string $until = null,
        ?string $heartbeat = null,
    ): WorkerStatus {
        return new WorkerStatus(
            'default',
            $owner,
            null === $until ? null : self::at($until),
            null === $heartbeat ? null : self::at($heartbeat),
            null,
            null,
            null,
        );
    }

    #[Test]
    public function aWorkerThatNeverRanHasNoHeartbeatAge(): void
    {
        static::assertNull(self::workerStatus()->heartbeatAge(self::at('09:00:00')));
    }

    #[Test]
    public function heartbeatAgeIsSecondsSinceTheLastBeat(): void
    {
        static::assertSame(90, self::workerStatus(heartbeat: '08:58:30')->heartbeatAge(self::at('09:00:00')));
    }

    #[Test]
    #[DataProvider('runningProvider')]
    public function isRunningWhileItHoldsTheLock(?string $owner, ?string $until, bool $expected): void
    {
        static::assertSame($expected, self::workerStatus($owner, $until)->isRunning(self::at('09:00:00')));
    }
}
