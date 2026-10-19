<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Schedule;

use Contenir\Queue\Exception\ScheduleException;
use Contenir\Queue\Schedule\Frequency;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('unit')]
final class FrequencyTest extends TestCase
{
    /**
     * @return array<string, array{Frequency, string}>
     */
    public static function describeProvider(): array
    {
        return [
            'one minute'     => [Frequency::everyMinutes(1), 'every minute'],
            'many minutes'   => [Frequency::everyMinutes(5), 'every 5 minutes'],
            'cron in UTC'    => [Frequency::cron('0 2 * * *'), '0 2 * * * (UTC)'],
            'cron in a zone' => [
                Frequency::cron('@daily', new DateTimeZone('Australia/Sydney')),
                '@daily (Australia/Sydney)',
            ],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function intervalProvider(): array
    {
        return [
            'handled on time'          => ['09:00:00', '09:05:00'],
            'handled a little late'    => ['09:02:30', '09:05:00'],
            'handled just before next' => ['09:04:59', '09:05:00'],
            'handled exactly at next'  => ['09:05:00', '09:10:00'],
            'several runs missed'      => ['09:17:00', '09:20:00'],
        ];
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-10-19 {$time}", new DateTimeZone('UTC'));
    }

    #[Test]
    public function aMinuteIsTheShortestInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A schedule can run at most once a minute, not every 0');

        Frequency::everyMinutes(0);
    }

    #[Test]
    public function anIntervalFirstFallsDueAtOnce(): void
    {
        static::assertSame('09:00:00', Frequency::everyMinutes(60)->first(self::at('09:00:00'))->format('H:i:s'));
    }

    #[Test]
    #[DataProvider('intervalProvider')]
    public function anIntervalKeepsToItsBeat(string $now, string $expected): void
    {
        static::assertSame(
            $expected,
            Frequency::everyMinutes(5)->next(self::at('09:00:00'), self::at($now))->format('H:i:s'),
        );
    }

    #[Test]
    public function cronFirstFallsDueAtTheNextMatch(): void
    {
        static::assertSame('09:15:00', Frequency::cron('15 * * * *')->first(self::at('09:00:00'))->format('H:i:s'));
    }

    #[Test]
    public function cronKeepsItsExpressionAndZone(): void
    {
        $frequency = Frequency::cron('15 3 * * 1', new DateTimeZone('Australia/Sydney'));

        static::assertSame(
            [null, '15 3 * * 1', 'Australia/Sydney'],
            [$frequency->minutes, $frequency->cron, $frequency->timezone->getName()],
        );
    }

    #[Test]
    public function cronReadsItsTimesInItsZone(): void
    {
        $next = Frequency::cron('0 2 * * *', new DateTimeZone('Australia/Sydney'))
            ->next(self::at('09:00:00'), self::at('09:00:00'));

        static::assertSame('2026-10-19 15:00:00 UTC', $next->format('Y-m-d H:i:s T'));
    }

    #[Test]
    public function cronRefusesAnythingElse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"every day" is not a cron expression');

        Frequency::cron('every day');
    }

    #[Test]
    public function cronTakesTheNextMatchAfterNow(): void
    {
        $next = Frequency::cron('*/15 * * * *')->next(self::at('08:00:00'), self::at('09:15:00'));

        static::assertSame('2026-10-19 09:30:00 UTC', $next->format('Y-m-d H:i:s T'));
    }

    #[Test]
    public function cronThatNeverMatchesIsReported(): void
    {
        try {
            Frequency::cron('0 0 30 2 *')->first(self::at('09:00:00'));
            static::fail('No exception');
        } catch (ScheduleException $e) {
            static::assertSame(
                ['The cron expression "0 0 30 2 *" never falls due', RuntimeException::class],
                [$e->getMessage(), $e->getPrevious()::class],
            );
        }
    }

    #[Test]
    #[DataProvider('describeProvider')]
    public function describesItselfForPeople(Frequency $frequency, string $expected): void
    {
        static::assertSame($expected, $frequency->describe());
    }

    #[Test]
    public function everyMinutesKeepsItsInterval(): void
    {
        $frequency = Frequency::everyMinutes(5);

        static::assertSame([5, null, 'UTC'], [$frequency->minutes, $frequency->cron, $frequency->timezone->getName()]);
    }
}
