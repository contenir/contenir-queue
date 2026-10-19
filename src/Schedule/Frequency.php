<?php

declare(strict_types=1);

namespace Contenir\Queue\Schedule;

use Contenir\Queue\Exception\ScheduleException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * How often a schedule runs: every so many minutes, or on a cron expression
 * read in a time zone. The worker looks for due schedules on every turn, so a
 * minute is the finest interval. The names follow contenir/extension-api's
 * Frequency, which hosts map onto this one.
 *
 * @api
 */
final readonly class Frequency
{
    private function __construct(
        public ?int $minutes,
        public ?string $cron,
        public DateTimeZone $timezone,
        private RecurrenceInterface $recurrence,
    ) {}

    /**
     * @param string $expression Five fields (minute, hour, day of month, month, day of week) or an alias such as
     *     "@daily". See {@see CronExpression} for what each field takes.
     * @param DateTimeZone|null $timezone The zone the expression's times are in; UTC if not given.
     *
     * @throws InvalidArgumentException When the expression is not valid cron.
     */
    public static function cron(string $expression, ?DateTimeZone $timezone = null): self
    {
        $timezone ??= CronExpression::utc();

        return new self(null, $expression, $timezone, new Cron(CronExpression::parse($expression), $timezone));
    }

    /**
     * @throws InvalidArgumentException When $minutes is less than one.
     */
    public static function everyMinutes(int $minutes): self
    {
        if ($minutes < 1) {
            throw new InvalidArgumentException("A schedule can run at most once a minute, not every {$minutes}");
        }

        return new self($minutes, null, CronExpression::utc(), new Interval($minutes));
    }

    /**
     * For people: "every 5 minutes", "0 2 * * * (Australia/Sydney)".
     */
    public function describe(): string
    {
        return match (true) {
            null !== $this->cron => "{$this->cron} ({$this->timezone->getName()})",
            1 === $this->minutes => 'every minute',
            default => "every {$this->minutes} minutes",
        };
    }

    /**
     * When a schedule first falls due, if it has never run: at once for an
     * interval, the next matching time for cron.
     *
     * @throws ScheduleException When a cron expression never matches.
     */
    public function first(DateTimeImmutable $now): DateTimeImmutable
    {
        return $this->recurrence->first($now);
    }

    /**
     * The run after one that fell due at `$due`, handled at `$now`: never at or
     * before `$now`, so runs missed while nothing was running are not made up.
     * An interval keeps to its own beat (due plus a whole number of
     * intervals); cron takes the next matching time.
     *
     * @throws ScheduleException When a cron expression never matches.
     */
    public function next(DateTimeImmutable $due, DateTimeImmutable $now): DateTimeImmutable
    {
        return $this->recurrence->next($due, $now);
    }
}
