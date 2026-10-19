<?php

declare(strict_types=1);

namespace Contenir\Queue\Monitor;

use Contenir\Queue\Exception\ScheduleException;
use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Job\Job;
use Contenir\Queue\Job\JobState;
use Contenir\Queue\Schedule\Schedule;
use Contenir\Queue\Schedule\ScheduleRun;
use Contenir\Queue\Schedule\Schedules;
use Contenir\Queue\Schedule\ScheduleState;
use Contenir\Queue\Storage\JobBrowserInterface;
use Contenir\Queue\Storage\ScheduleStoreInterface;
use Contenir\Queue\Storage\WorkerStoreInterface;
use Contenir\Queue\Worker\WorkerOptions;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

use function array_map;

/**
 * The data a host's screens and checks need, with no UI: jobs by state with
 * retry and delete, schedules with their last and next runs, and the
 * heartbeat behind the "Background jobs aren't running" warning.
 *
 * @api
 */
final readonly class QueueMonitor
{
    public function __construct(
        private JobBrowserInterface $jobs,
        private ScheduleStoreInterface $scheduleStore,
        private Schedules $schedules,
        private WorkerStoreInterface $workers,
        private ClockInterface $clock,
    ) {}

    private static function status(Schedule $schedule, ?ScheduleState $state, DateTimeImmutable $now): ScheduleStatus
    {
        try {
            $next = $state->nextRunAt ?? $schedule->frequency->first($now);
        } catch (ScheduleException) {
            $next = null;
        }

        return new ScheduleStatus(
            name: $schedule->name,
            jobClass: $schedule->jobClass,
            queue: $schedule->queue,
            frequency: $schedule->frequency->describe(),
            lastRunAt: $state?->lastRunAt,
            nextRunAt: $next,
            lastJobId: $state?->lastJobId,
            lastJobState: $state?->lastJobState,
        );
    }

    /**
     * @throws StorageException
     */
    public function counts(): JobCounts
    {
        $counts = $this->jobs->countByState();

        return new JobCounts($counts['waiting'], $counts['running'], $counts['done'], $counts['failed']);
    }

    /**
     * Delete a waiting, done or failed job. A running job cannot be deleted.
     *
     * @throws StorageException
     */
    public function delete(int $id): bool
    {
        return $this->jobs->delete($id);
    }

    /**
     * @throws StorageException
     */
    public function health(string $workerName = WorkerOptions::DEFAULT_NAME): QueueHealth
    {
        $now    = $this->clock->now();
        $worker = $this->workers->find($workerName);
        $oldest = $this->jobs->oldestWaitingSince();
        $counts = $this->counts();

        return new QueueHealth(
            heartbeatAt: $worker?->heartbeatAt,
            heartbeatAge: $worker?->heartbeatAge($now),
            workerRunning: $worker?->isRunning($now) ?? false,
            waiting: $counts->waiting,
            oldestWaitingAge: null === $oldest ? null : $now->getTimestamp() - $oldest->getTimestamp(),
            failed: $counts->failed,
        );
    }

    /**
     * @throws StorageException
     */
    public function job(int $id): ?Job
    {
        return $this->jobs->find($id);
    }

    /**
     * @return list<Job>
     *
     * @throws StorageException
     */
    public function jobs(JobState $state, int $limit = 50, int $offset = 0): array
    {
        return $this->jobs->findByState($state, $limit, $offset);
    }

    /**
     * Give a failed job a fresh set of attempts, starting now.
     *
     * @throws StorageException
     */
    public function retry(int $id): bool
    {
        return $this->jobs->retryFailed($id, $this->clock->now());
    }

    /**
     * A schedule's most recent runs, newest first.
     *
     * @return list<ScheduleRun>
     *
     * @throws StorageException
     */
    public function runs(string $schedule, int $limit = 20): array
    {
        return $this->scheduleStore->runs($schedule, $limit);
    }

    /**
     * Every schedule the site runs, in the order they were added.
     *
     * @return list<ScheduleStatus>
     *
     * @throws StorageException
     */
    public function schedules(): array
    {
        $states = $this->scheduleStore->states();
        $now    = $this->clock->now();

        return array_map(
            static fn(Schedule $schedule): ScheduleStatus => self::status(
                $schedule,
                $states[$schedule->name] ?? null,
                $now,
            ),
            $this->schedules->all(),
        );
    }
}
