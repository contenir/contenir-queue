<?php

declare(strict_types=1);

namespace Contenir\Queue\Worker;

use DateTimeImmutable;

/**
 * A worker's row: who holds its lock and until when, and when it last showed
 * signs of life. Times are UTC.
 *
 * @api
 */
final readonly class WorkerStatus
{
    /**
     * @mago-expect lint:excessive-parameter-list One parameter per column of the queue_worker table.
     */
    public function __construct(
        public string $name,
        public ?string $owner,
        public ?DateTimeImmutable $lockedUntil,
        public ?DateTimeImmutable $heartbeatAt,
        public ?DateTimeImmutable $startedAt,
        public ?string $host,
        public ?int $pid,
    ) {}

    /**
     * Seconds since the last heartbeat, or null if the worker has never run.
     */
    public function heartbeatAge(DateTimeImmutable $now): ?int
    {
        return null === $this->heartbeatAt ? null : $now->getTimestamp() - $this->heartbeatAt->getTimestamp();
    }

    /**
     * Whether a worker holds the lock at the given time.
     */
    public function isRunning(DateTimeImmutable $now): bool
    {
        return null !== $this->owner && null !== $this->lockedUntil && $this->lockedUntil >= $now;
    }
}
