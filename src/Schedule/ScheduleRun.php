<?php

declare(strict_types=1);

namespace Contenir\Queue\Schedule;

use Contenir\Queue\Job\JobState;
use DateTimeImmutable;

/**
 * One time a schedule fell due: the job it queued, or none when the run was
 * skipped because the last one had not finished. The job's state is null once
 * the job has been deleted. Times are UTC.
 *
 * @api
 */
final readonly class ScheduleRun
{
    /**
     * @mago-expect lint:excessive-parameter-list One parameter per column of queue_schedule_run, plus the job's state.
     */
    public function __construct(
        public int $id,
        public string $schedule,
        public DateTimeImmutable $dueAt,
        public DateTimeImmutable $recordedAt,
        public ?int $jobId,
        public ?JobState $jobState,
    ) {}

    public function wasSkipped(): bool
    {
        return null === $this->jobId;
    }
}
