<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Storage;

use Contenir\Queue\Storage\WorkerStoreInterface;
use Contenir\Queue\Worker\WorkerIdentity;
use Contenir\Queue\Worker\WorkerStatus;
use DateTimeImmutable;
use Override;

/**
 * Worker rows in an array, behaving as PhpDbWorkerStore does.
 */
final class InMemoryWorkerStore implements WorkerStoreInterface
{
    /** @var array<string, WorkerStatus> */
    public array $workers = [];

    /** @var list<string> */
    public array $renewals = [];

    #[Override]
    public function acquire(
        string $name,
        WorkerIdentity $worker,
        DateTimeImmutable $lockedUntil,
        DateTimeImmutable $now,
    ): bool {
        $current = $this->workers[$name] ?? null;
        if (null !== $current && null !== $current->owner && $current->lockedUntil >= $now) {
            return false;
        }

        $this->workers[$name] = new WorkerStatus(
            $name,
            $worker->lock,
            $lockedUntil,
            $now,
            $now,
            $worker->host,
            $worker->pid,
        );

        return true;
    }

    #[Override]
    public function find(string $name): ?WorkerStatus
    {
        return $this->workers[$name] ?? null;
    }

    /**
     * Give the lock to another worker, as if this one's had run out.
     */
    public function hijack(string $name, string $owner, DateTimeImmutable $until): void
    {
        $this->workers[$name] = new WorkerStatus($name, $owner, $until, $until, $until, 'other', 1);
    }

    #[Override]
    public function release(string $name, string $owner, DateTimeImmutable $now): void
    {
        $current = $this->workers[$name] ?? null;
        if (null === $current || $current->owner !== $owner) {
            return;
        }

        $this->workers[$name] = new WorkerStatus(
            $name,
            null,
            null,
            $now,
            $current->startedAt,
            $current->host,
            $current->pid,
        );
    }

    #[Override]
    public function renew(string $name, string $owner, DateTimeImmutable $lockedUntil, DateTimeImmutable $now): bool
    {
        $current = $this->workers[$name] ?? null;
        if (null === $current || $current->owner !== $owner) {
            return false;
        }

        $this->renewals[]     = $now->format('H:i:s');
        $this->workers[$name] = new WorkerStatus(
            $name,
            $owner,
            $lockedUntil,
            $now,
            $current->startedAt,
            $current->host,
            $current->pid,
        );

        return true;
    }
}
