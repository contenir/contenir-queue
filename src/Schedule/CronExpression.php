<?php

declare(strict_types=1);

namespace Contenir\Queue\Schedule;

use Contenir\Queue\Exception\ScheduleException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

use function count;
use function is_array;
use function preg_split;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * A standard five-field cron expression: minute, hour, day of month, month and
 * day of week. Each field takes what {@see CronField} reads; months and days
 * of the week also take their three-letter English names, and Sunday is 0 or
 * 7. When both day fields are restricted, a day matching either one matches,
 * as in cron itself. The aliases `@yearly`, `@annually`, `@monthly`,
 * `@weekly`, `@daily`, `@midnight` and `@hourly` stand for the usual
 * expressions.
 *
 * Times are matched on the wall clock of the time zone they are given in.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity Matching walks the fields one by one.
 */
final readonly class CronExpression
{
    private const array ALIASES = [
        '@yearly'   => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly'  => '0 0 1 * *',
        '@weekly'   => '0 0 * * 0',
        '@daily'    => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly'   => '0 * * * *',
    ];

    private const array MONTHS = [
        'jan' => 1,
        'feb' => 2,
        'mar' => 3,
        'apr' => 4,
        'may' => 5,
        'jun' => 6,
        'jul' => 7,
        'aug' => 8,
        'sep' => 9,
        'oct' => 10,
        'nov' => 11,
        'dec' => 12,
    ];

    private const array WEEKDAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    /**
     * How far ahead to look before deciding an expression never matches. Any
     * day that exists comes round within this many years.
     */
    private const int SEARCH_YEARS = 30;

    /**
     * @param array{minute: array<int, true>, hour: array<int, true>, day: array<int, true>,
     *     month: array<int, true>, weekday: array<int, true>} $allowed
     * @param bool $eitherDay Whether a day matches on either day field, as when both are restricted.
     */
    private function __construct(
        private string $expression,
        private array $allowed,
        private bool $eitherDay,
    ) {}

    /**
     * @throws InvalidArgumentException When the expression is not one this class reads.
     */
    public static function parse(string $expression): self
    {
        try {
            $fields = preg_split('/\s+/', self::ALIASES[strtolower(trim($expression))] ?? trim($expression));
            if (! is_array($fields) || 5 !== count($fields)) {
                throw new InvalidArgumentException('it needs five fields');
            }

            $day      = $fields[2] ?? '';
            $weekday  = $fields[4] ?? '';
            $weekdays = (new CronField(0, 7, self::WEEKDAYS))->allowed($weekday);

            return new self(
                $expression,
                [
                    'minute'  => (new CronField(0, 59))->allowed($fields[0]),
                    'hour'    => (new CronField(0, 23))->allowed($fields[1] ?? ''),
                    'day'     => (new CronField(1, 31))->allowed($day),
                    'month'   => (new CronField(1, 12, self::MONTHS))->allowed($fields[3] ?? ''),
                    'weekday' => $weekdays + ($weekdays[7] ?? false ? [0 => true] : []),
                ],
                ! str_starts_with($day, '*') && ! str_starts_with($weekday, '*'),
            );
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(
                "\"{$expression}\" is not a cron expression: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    /**
     * @mago-expect analysis:unhandled-thrown-type "UTC" is always a valid time zone.
     */
    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    /**
     * The start of the hour after the given one.
     */
    private static function nextHour(DateTimeImmutable $time): DateTimeImmutable
    {
        $next = $time->modify('+1 hour');

        return $next->setTime((int) $next->format('G'), 0);
    }

    /**
     * The given time's wall-clock date, hour and minute, held in UTC, where
     * adding a day or an hour is never bent by daylight saving.
     */
    private static function wallClock(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(self::utc())
            ->setDate((int) $time->format('Y'), (int) $time->format('n'), (int) $time->format('j'))
            ->setTime((int) $time->format('G'), (int) $time->format('i'));
    }

    /**
     * The first matching minute after the given time, in its time zone. A
     * matching time that a daylight-saving change skips runs at the same
     * minute past the next hour.
     *
     * @throws ScheduleException When no time matches, as for the 30th of February.
     */
    public function next(DateTimeImmutable $after): DateTimeImmutable
    {
        $years    = self::SEARCH_YEARS;
        $cursor   = self::wallClock($after)->modify('+1 minute');
        $lastYear = $cursor->modify("+{$years} years")->format('Y');

        while ($cursor->format('Y') <= $lastYear) {
            $moved = $this->move($cursor);
            if (null === $moved) {
                return $after->setDate(
                    (int) $cursor->format('Y'),
                    (int) $cursor->format('n'),
                    (int) $cursor->format('j'),
                )
                    ->setTime((int) $cursor->format('G'), (int) $cursor->format('i'));
            }

            $cursor = $moved;
        }

        throw ScheduleException::neverDue($this->expression);
    }

    private function allows(string $field, int $value): bool
    {
        return $this->allowed[$field][$value] ?? false;
    }

    private function dayMatches(DateTimeImmutable $time): bool
    {
        $byDate    = $this->allows('day', (int) $time->format('d'));
        $byWeekday = $this->allowed['weekday'][$time->format('w')] ?? false;

        return $this->eitherDay ? $byDate || $byWeekday : $byDate && $byWeekday;
    }

    /**
     * The next time worth trying when the given one does not match, skipping
     * the rest of a month, day or hour that cannot; null when it matches.
     */
    private function move(DateTimeImmutable $time): ?DateTimeImmutable
    {
        return match (true) {
            ! $this->allows('month', (int) $time->format('m')) => $time->modify('first day of next month midnight'),
            ! $this->dayMatches($time) => $time->modify('tomorrow'),
            ! $this->allows('hour', (int) $time->format('H')) => self::nextHour($time),
            ! $this->allows('minute', (int) $time->format('i')) => $time->modify('+1 minute'),
            default => null,
        };
    }
}
