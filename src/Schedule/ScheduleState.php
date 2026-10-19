<?php

declare(strict_types=1);

namespace Contenir\Queue\Schedule;

use Contenir\Queue\Job\JobState;
use DateTimeImmutable;

/**
 * Where a schedule stands: when it next falls due, when it last did, and the
 * job its last run queued, with that job's state if the job still exists.
 * Times are UTC.
 *
 * @api
 */
final readonly class ScheduleState
{
    public function __construct(
        public string $name,
        public DateTimeImmutable $nextRunAt,
        public ?DateTimeImmutable $lastRunAt = null,
        public ?int $lastJobId = null,
        public ?JobState $lastJobState = null,
    ) {}

    /**
     * Whether the job the last run queued is still waiting or running, in
     * which case the next run is skipped rather than overlap it.
     */
    public function isLastRunUnfinished(): bool
    {
        return JobState::Waiting === $this->lastJobState || JobState::Running === $this->lastJobState;
    }
}
