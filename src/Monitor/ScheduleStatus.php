<?php

declare(strict_types=1);

namespace Contenir\Queue\Monitor;

use Contenir\Queue\Job\JobState;
use DateTimeImmutable;

/**
 * A schedule as a screen shows it: what it runs and how often, when it last
 * ran and how that went, and when it runs next. Times are UTC.
 *
 * @api
 */
final readonly class ScheduleStatus
{
    /**
     * @param string $frequency For people, such as "every 5 minutes".
     * @param ?DateTimeImmutable $nextRunAt Null when it cannot be worked out (a cron expression that never matches).
     * @param ?JobState $lastJobState Null when it has not run, or its last job has been deleted.
     *
     * @mago-expect lint:excessive-parameter-list One parameter per column of the schedules screen.
     */
    public function __construct(
        public string $name,
        public string $jobClass,
        public string $queue,
        public string $frequency,
        public ?DateTimeImmutable $lastRunAt,
        public ?DateTimeImmutable $nextRunAt,
        public ?int $lastJobId,
        public ?JobState $lastJobState,
    ) {}
}
