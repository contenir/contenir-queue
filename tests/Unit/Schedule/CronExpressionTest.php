<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Schedule;

use Contenir\Queue\Exception\ScheduleException;
use Contenir\Queue\Schedule\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Times here are UTC unless a test says otherwise. 19 October 2026 is a
 * Monday. 29 February falls on a Sunday in 2060, 2088 and then 2128.
 */
#[Group('unit')]
final class CronExpressionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function daysAndAliasesProvider(): array
    {
        return [
            'a weekday'                   => ['0 9 * * 5', '2026-10-19 09:00', '2026-10-23 09:00'],
            'Sunday as 0'                 => ['0 9 * * 0', '2026-10-19 09:00', '2026-10-25 09:00'],
            'Sunday as 7'                 => ['0 9 * * 7', '2026-10-19 09:00', '2026-10-25 09:00'],
            'Saturday'                    => ['0 9 * * 6', '2026-10-19 09:00', '2026-10-24 09:00'],
            'a weekday by name'           => ['0 9 * * fri', '2026-10-19 09:00', '2026-10-23 09:00'],
            'a range of weekdays'         => ['0 9 * * mon-fri', '2026-10-23 10:00', '2026-10-26 09:00'],
            'a month by name'             => ['0 0 1 jan *', '2026-10-19 09:00', '2027-01-01 00:00'],
            'day or weekday, by weekday'  => ['0 0 13 * 5', '2026-10-19 09:00', '2026-10-23 00:00'],
            'day or weekday, by day'      => ['0 0 20 * 5', '2026-10-19 09:00', '2026-10-20 00:00'],
            'a stepped day and a weekday' => ['0 0 */2 * 5', '2026-10-19 09:00', '2026-10-23 00:00'],
            'a day and any weekday'       => ['0 0 13 * *', '2026-10-19 09:00', '2026-11-13 00:00'],
            'every fifteen minutes'       => ['*/15 * * * *', '2026-10-19 09:16', '2026-10-19 09:30'],
            'a list of hours'             => ['0 6,18 * * *', '2026-10-19 09:00', '2026-10-19 18:00'],
            'extra spaces and tabs'       => [" 0\t2  * * * ", '2026-10-19 09:10', '2026-10-20 02:00'],
            'hourly'                      => ['@hourly', '2026-10-19 09:10', '2026-10-19 10:00'],
            'daily'                       => ['@daily', '2026-10-19 09:10', '2026-10-20 00:00'],
            'midnight'                    => ['@midnight', '2026-10-19 09:10', '2026-10-20 00:00'],
            'weekly'                      => ['@weekly', '2026-10-19 09:10', '2026-10-25 00:00'],
            'monthly'                     => ['@monthly', '2026-10-19 09:10', '2026-11-01 00:00'],
            'yearly'                      => ['@yearly', '2026-10-19 09:10', '2027-01-01 00:00'],
            'annually'                    => ['@annually', '2026-10-19 09:10', '2027-01-01 00:00'],
            'an alias in capitals'        => [' @DAILY ', '2026-10-19 09:10', '2026-10-20 00:00'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'words'              => ['every day', 'it needs five fields'],
            'four fields'        => ['0 0 * *', 'it needs five fields'],
            'six fields'         => ['0 0 * * * *', 'it needs five fields'],
            'empty'              => ['', 'it needs five fields'],
            'an unknown alias'   => ['@sometimes', 'it needs five fields'],
            'minute 60'          => ['60 * * * *', '"60" is not a value from 0 to 59'],
            'hour 24'            => ['0 24 * * *', '"24" is not a value from 0 to 23'],
            'day 0'              => ['0 0 0 * *', '"0" is not a value from 1 to 31'],
            'day 32'             => ['0 0 32 * *', '"32" is not a value from 1 to 31'],
            'month 0'            => ['0 0 1 0 *', '"0" is not a value from 1 to 12'],
            'month 13'           => ['0 0 1 13 *', '"13" is not a value from 1 to 12'],
            'weekday 8'          => ['0 0 * * 8', '"8" is not a value from 0 to 7'],
            'a weekday as month' => ['0 0 1 mon *', '"mon" is not a value from 1 to 12'],
            'a month as weekday' => ['0 0 * * jan', '"jan" is not a value from 0 to 7'],
            'a name as an hour'  => ['0 jan * * *', '"jan" is not a value from 0 to 23'],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function nextProvider(): array
    {
        return [
            'every minute'                   => ['* * * * *', '2026-10-19 09:00', '2026-10-19 09:01'],
            'strictly after a match'         => ['0 * * * *', '2026-10-19 09:00', '2026-10-19 10:00'],
            'seconds are ignored'            => ['* * * * *', '2026-10-19 09:00:59', '2026-10-19 09:01'],
            'later this hour'                => ['30 * * * *', '2026-10-19 09:10', '2026-10-19 09:30'],
            'the last minute of the hour'    => ['59 * * * *', '2026-10-19 09:10', '2026-10-19 09:59'],
            'into the next hour'             => ['5 * * * *', '2026-10-19 09:10', '2026-10-19 10:05'],
            'the last hour of the day'       => ['0 23 * * *', '2026-10-19 09:10', '2026-10-19 23:00'],
            'into the next day'              => ['0 2 * * *', '2026-10-19 09:10', '2026-10-20 02:00'],
            'from the last minute of a day'  => ['0 0 * * *', '2026-10-19 23:59', '2026-10-20 00:00'],
            'the last day of a month'        => ['0 0 31 * *', '2026-10-19 09:00', '2026-10-31 00:00'],
            'past a 30-day month'            => ['0 0 31 * *', '2026-11-01 00:00', '2026-12-31 00:00'],
            'into the next month'            => ['0 0 1 * *', '2026-10-19 09:00', '2026-11-01 00:00'],
            'December'                       => ['0 0 1 12 *', '2026-10-19 09:00', '2026-12-01 00:00'],
            'into the next year'             => ['0 0 1 1 *', '2026-10-19 09:00', '2027-01-01 00:00'],
            'from the last minute of a year' => ['* * * * *', '2026-12-31 23:59', '2027-01-01 00:00'],
            'the next leap day'              => ['0 0 29 2 *', '2026-10-19 09:00', '2028-02-29 00:00'],
            'not in 2100'                    => ['0 0 29 2 *', '2096-03-01 00:00', '2104-02-29 00:00'],
            'but in 2000'                    => ['0 0 29 2 *', '1996-03-01 00:00', '2000-02-29 00:00'],
        ];
    }

    private static function sydney(): DateTimeZone
    {
        return new DateTimeZone('Australia/Sydney');
    }

    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    #[Test]
    public function aDayThatNeverComesIsReported(): void
    {
        $this->expectException(ScheduleException::class);
        $this->expectExceptionMessage('The cron expression "0 0 30 2 *" never falls due');

        CronExpression::parse('0 0 30 2 *')->next(self::utc('2026-10-19 09:00'));
    }

    #[Test]
    public function aMatchHasNoSeconds(): void
    {
        $next = CronExpression::parse('* * * * *')->next(self::utc('2026-10-19 09:00:30.5'));

        static::assertSame('09:01:00.000000', $next->format('H:i:s.u'));
    }

    #[Test]
    public function aTimeTheClocksRepeatRunsOnce(): void
    {
        $first = (new DateTimeImmutable('2026-04-05 02:30 +11:00'))->setTimezone(self::sydney());

        $next = CronExpression::parse('30 2 * * *')->next($first);

        static::assertSame('2026-04-06 02:30 +10:00', $next->format('Y-m-d H:i P'));
    }

    #[Test]
    public function aTimeTheClocksSkipRunsAnHourLater(): void
    {
        $next = CronExpression::parse('30 2 * * *')->next(new DateTimeImmutable('2026-10-03 12:00', self::sydney()));

        static::assertSame('2026-10-04 03:30 +11:00', $next->format('Y-m-d H:i P'));
    }

    #[Test]
    #[DataProvider('nextProvider')]
    #[DataProvider('daysAndAliasesProvider')]
    public function findsTheNextMatchingMinute(string $expression, string $after, string $expected): void
    {
        $next = CronExpression::parse($expression)->next(self::utc($after));

        static::assertSame($expected, $next->format('Y-m-d H:i'));
    }

    #[Test]
    public function keepsTheReasonAsThePreviousException(): void
    {
        try {
            CronExpression::parse('60 * * * *');
            static::fail('No exception');
        } catch (InvalidArgumentException $e) {
            static::assertSame('"60" is not a value from 0 to 59', $e->getPrevious()?->getMessage());
        }
    }

    #[Test]
    public function looksNoFurtherThanThirtyYearsAhead(): void
    {
        $this->expectException(ScheduleException::class);

        $this->expectExceptionMessage('The cron expression "0 0 29 2 */7" never falls due');

        CronExpression::parse('0 0 29 2 */7')->next(self::utc('2097-03-01 00:00'));
    }

    #[Test]
    public function looksThirtyYearsAhead(): void
    {
        $next = CronExpression::parse('0 0 29 2 */7')->next(self::utc('2098-03-01 00:00'));

        static::assertSame('2128-02-29', $next->format('Y-m-d'));
    }

    #[Test]
    public function matchesOnTheWallClockOfTheGivenZone(): void
    {
        $next = CronExpression::parse('0 2 * * *')->next(new DateTimeImmutable('2026-10-19 20:00', self::sydney()));

        static::assertSame('2026-10-20 02:00 +11:00', $next->format('Y-m-d H:i P'));
    }

    #[Test]
    #[DataProvider('invalidProvider')]
    public function refusesWhatItCannotRead(string $expression, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("\"{$expression}\" is not a cron expression: {$reason}");

        CronExpression::parse($expression);
    }
}
