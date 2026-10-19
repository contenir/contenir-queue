<?php

declare(strict_types=1);

namespace Contenir\Queue\Storage;

use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Job\Job;
use DateTimeImmutable;

/**
 * Durable storage for jobs. Claiming is atomic, so any number of workers and
 * web requests can take jobs from the same table without running one twice.
 *
 * Finishing a job (complete, retry, fail) only succeeds while the job is still
 * running under the lock it was claimed with, and says whether it did.
 *
 * @api
 */
interface JobStoreInterface
{
    /**
     * Claim the next due waiting job: lock it, mark it running and count the
     * attempt. An empty list of queues means any queue.
     *
     * @param list<string> $queues
     *
     * @throws StorageException
     */
    public function claimNext(array $queues, string $lockedBy, DateTimeImmutable $now): ?Job;

    /**
     * Mark a running job done.
     *
     * @throws StorageException
     */
    public function complete(Job $job, DateTimeImmutable $now): bool;

    /**
     * Mark a running job failed for good.
     *
     * @throws StorageException
     */
    public function fail(Job $job, string $error, DateTimeImmutable $now): bool;

    /**
     * @throws StorageException
     */
    public function find(int $id): ?Job;

    /**
     * Store a new waiting job and return its id.
     *
     * @throws StorageException
     */
    public function push(
        string $queue,
        string $jobClass,
        string $encodedPayload,
        DateTimeImmutable $nextAttemptAt,
        DateTimeImmutable $now,
    ): int;

    /**
     * Put a running job back to wait for its next attempt.
     *
     * @throws StorageException
     */
    public function retry(Job $job, DateTimeImmutable $nextAttemptAt, string $error, DateTimeImmutable $now): bool;
}
