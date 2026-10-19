<?php

declare(strict_types=1);

namespace Contenir\Queue\Storage;

use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Job\Job;
use Contenir\Queue\Job\JobState;
use DateTimeImmutable;

/**
 * Jobs as a host's screens see them: counts, lists by state, and the retry
 * and delete buttons.
 *
 * @api
 */
interface JobBrowserInterface
{
    /**
     * How many jobs are in each state, by the state's value; every state is
     * listed.
     *
     * @return array{waiting: int, running: int, done: int, failed: int}
     *
     * @throws StorageException
     */
    public function countByState(): array;

    /**
     * Delete a job that is not running. False if there was no such job.
     *
     * @throws StorageException
     */
    public function delete(int $id): bool;

    /**
     * @throws StorageException
     */
    public function find(int $id): ?Job;

    /**
     * Jobs in a state, in the order a screen shows them: waiting jobs by next
     * attempt, running ones by when they were locked, done and failed ones
     * newest first.
     *
     * @return list<Job>
     *
     * @throws StorageException
     */
    public function findByState(JobState $state, int $limit, int $offset): array;

    /**
     * When the longest-waiting job was pushed, or null when none is waiting.
     *
     * @throws StorageException
     */
    public function oldestWaitingSince(): ?DateTimeImmutable;

    /**
     * Give a failed job a fresh set of attempts, starting now. False if there
     * was no such failed job.
     *
     * @throws StorageException
     */
    public function retryFailed(int $id, DateTimeImmutable $now): bool;
}
