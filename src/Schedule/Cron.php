<?php

declare(strict_types=1);

namespace Contenir\Queue\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use Override;

/**
 * A cron expression read on the wall clock of a time zone. Times it returns
 * are UTC.
 *
 * @internal
 */
final readonly class Cron implements RecurrenceInterface
{
    public function __construct(
        private CronExpression $expression,
        private DateTimeZone $timezone,
    ) {}

    /**
     * The next matching time.
     */
    #[Override]
    public function first(DateTimeImmutable $now): DateTimeImmutable
    {
        return $this->next($now, $now);
    }

    /**
     * The next matching time after now, whenever the last run fell due.
     */
    #[Override]
    public function next(DateTimeImmutable $due, DateTimeImmutable $now): DateTimeImmutable
    {
        return $this->expression->next($now->setTimezone($this->timezone))->setTimezone(CronExpression::utc());
    }
}
