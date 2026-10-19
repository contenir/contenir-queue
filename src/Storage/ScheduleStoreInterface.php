<?php

declare(strict_types=1);

namespace Contenir\Queue\Storage;

use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Schedule\ScheduleRun;
use Contenir\Queue\Schedule\ScheduleState;
use DateTimeImmutable;

/**
 * Where schedules stand and what each run did. Taking a due run is atomic,
 * so the worker and web requests can both look for due schedules without
 * queuing one twice.
 *
 * @api
 */
interface ScheduleStoreInterface
{
    /**
     * Take the run due at `$due` by moving the schedule on to `$next`. False
     * when another process took it first.
     *
     * @throws StorageException
     */
    public function advance(
        string $name,
        DateTimeImmutable $due,
        DateTimeImmutable $next,
        DateTimeImmutable $now,
    ): bool;

    /**
     * Delete runs recorded before the given time, and return how many.
     *
     * @throws StorageException
     */
    public function pruneRuns(DateTimeImmutable $before): int;

    /**
     * Record a run: the job it queued, or null when it was skipped.
     *
     * @throws StorageException
     */
    public function recordRun(string $name, DateTimeImmutable $due, ?int $jobId, DateTimeImmutable $now): void;

    /**
     * A schedule's most recent runs, newest first.
     *
     * @return list<ScheduleRun>
     *
     * @throws StorageException
     */
    public function runs(string $name, int $limit): array;

    /**
     * Start tracking a schedule that has never run. False when another
     * process started it first.
     *
     * @throws StorageException
     */
    public function start(string $name, DateTimeImmutable $nextRunAt, DateTimeImmutable $now): bool;

    /**
     * Every tracked schedule's state, by name.
     *
     * @return array<string, ScheduleState>
     *
     * @throws StorageException
     */
    public function states(): array;
}
