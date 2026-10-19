<?php

declare(strict_types=1);

namespace Contenir\Queue\Schedule;

use Contenir\Queue\Exception\ScheduleException;
use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\QueueInterface;
use Contenir\Queue\Storage\ScheduleStoreInterface;
use DateTimeImmutable;
use JsonException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Pushes a job for each schedule that has fallen due. The worker calls it on
 * every turn, and so does the drain at the end of a web request.
 *
 * A run whose last job is still waiting or running is skipped, not queued
 * beside it, and either way the run is recorded. Runs missed while nothing
 * was running are not made up: the schedule runs once and moves on.
 *
 * @api
 */
final readonly class Scheduler
{
    public function __construct(
        private Schedules $schedules,
        private ScheduleStoreInterface $store,
        private QueueInterface $queue,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * Queue every due schedule's job, and return how many were queued.
     *
     * @throws StorageException
     */
    public function tick(): int
    {
        $now    = $this->clock->now();
        $states = $this->store->states();
        $queued = 0;

        foreach ($this->schedules->all() as $schedule) {
            try {
                if ($this->tickOne($schedule, $states[$schedule->name] ?? null, $now)) {
                    ++$queued;
                }
            } catch (ScheduleException|JsonException $e) {
                $this->logger->error('Schedule {name} cannot run: {error}', [
                    'name'  => $schedule->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $queued;
    }

    /**
     * Start tracking a schedule that has never run. Null when another process
     * started it first; it gets the run.
     *
     * @throws ScheduleException
     * @throws StorageException
     */
    private function start(Schedule $schedule, DateTimeImmutable $now): ?ScheduleState
    {
        $first = $schedule->frequency->first($now);

        return $this->store->start($schedule->name, $first, $now) ? new ScheduleState($schedule->name, $first) : null;
    }

    /**
     * @throws JsonException
     * @throws ScheduleException
     * @throws StorageException
     */
    private function tickOne(Schedule $schedule, ?ScheduleState $state, DateTimeImmutable $now): bool
    {
        $state ??= $this->start($schedule, $now);
        if (null === $state || $state->nextRunAt > $now) {
            return false;
        }

        $due  = $state->nextRunAt;
        $next = $schedule->frequency->next($due, $now);
        if (! $this->store->advance($schedule->name, $due, $next, $now)) {
            return false;
        }

        if ($state->isLastRunUnfinished()) {
            $this->store->recordRun($schedule->name, $due, null, $now);
            $this->logger->warning('Schedule {name} skipped: its last run (job {job}) has not finished', [
                'name' => $schedule->name,
                'job'  => $state->lastJobId,
            ]);

            return false;
        }

        $jobId = $this->queue->push($schedule->jobClass, $schedule->payload, $schedule->queue);
        $this->store->recordRun($schedule->name, $due, $jobId, $now);
        $this->logger->info('Schedule {name} queued job {job}', ['name' => $schedule->name, 'job' => $jobId]);

        return true;
    }
}
