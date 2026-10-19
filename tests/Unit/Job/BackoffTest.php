<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Job;

use Contenir\Queue\Job\Backoff;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class BackoffTest extends TestCase
{
    /**
     * @return array<string, array{int, ?int}>
     */
    public static function defaultLadderProvider(): array
    {
        return [
            'first failure waits a minute'     => [1, 60],
            'second failure waits two minutes' => [2, 120],
            'third failure waits four minutes' => [3, 240],
            'eleventh failure waits 1,024 min' => [11, 61_440],
            'twelfth failure waits 24 hours'   => [12, 86_400],
            'thirteenth failure is the last'   => [13, null],
            'later attempts get no more waits' => [20, null],
        ];
    }

    /**
     * @return array<string, array{int, int, int, string}>
     */
    public static function invalidSettingsProvider(): array
    {
        return [
            'first wait of zero'      => [0, 60, 2, 'The first retry wait must be at least one second'],
            'ceiling below the first' => [60, 59, 2, 'The longest retry wait cannot be shorter than the first'],
            'factor that never grows' => [60, 120, 1, 'The retry wait must at least double after each failure'],
        ];
    }

    #[Test]
    public function ceilingEqualToTheFirstWaitAllowsOneRetry(): void
    {
        $backoff = new Backoff(30, 30, 3);

        static::assertSame([30, null], [$backoff->delayAfter(1), $backoff->delayAfter(2)]);
    }

    #[Test]
    public function defaultsAreOneMinuteToADayDoubling(): void
    {
        $backoff = new Backoff();

        static::assertSame([60, 86_400, 2], [$backoff->initialSeconds, $backoff->maxSeconds, $backoff->factor]);
    }

    #[Test]
    public function factorMultipliesEachWait(): void
    {
        $backoff = new Backoff(10, 1_000, 3);

        static::assertSame([10, 30, 90, 270, 810, 1_000, null], [
            $backoff->delayAfter(1),
            $backoff->delayAfter(2),
            $backoff->delayAfter(3),
            $backoff->delayAfter(4),
            $backoff->delayAfter(5),
            $backoff->delayAfter(6),
            $backoff->delayAfter(7),
        ]);
    }

    #[Test]
    #[DataProvider('invalidSettingsProvider')]
    public function rejectsSettingsThatCannotEnd(int $initial, int $max, int $factor, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Backoff($initial, $max, $factor);
    }

    #[Test]
    public function smallestValidSettingsAreAccepted(): void
    {
        $backoff = new Backoff(1, 1, 2);

        static::assertSame(1, $backoff->delayAfter(1));
    }

    #[Test]
    #[DataProvider('defaultLadderProvider')]
    public function waitsDoubleUpToTheCeilingThenStop(int $attempt, ?int $expected): void
    {
        static::assertSame($expected, (new Backoff())->delayAfter($attempt));
    }
}
