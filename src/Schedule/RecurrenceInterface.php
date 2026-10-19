<?php

declare(strict_types=1);

namespace Contenir\Queue\Schedule;

use Contenir\Queue\Exception\ScheduleException;
use DateTimeImmutable;

/**
 * When a schedule's runs fall: the rule inside a {@see Frequency}.
 *
 * @internal
 */
interface RecurrenceInterface
{
    /**
     * When a schedule that has never run first falls due.
     *
     * @throws ScheduleException
     */
    public function first(DateTimeImmutable $now): DateTimeImmutable;

    /**
     * The run after one that fell due at `$due`, handled at `$now`: always
     * after `$now`, so runs missed while nothing was running are not made up.
     *
     * @throws ScheduleException
     */
    public function next(DateTimeImmutable $due, DateTimeImmutable $now): DateTimeImmutable;
}
