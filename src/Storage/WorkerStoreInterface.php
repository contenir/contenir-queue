<?php

declare(strict_types=1);

namespace Contenir\Queue\Storage;

use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Worker\WorkerIdentity;
use Contenir\Queue\Worker\WorkerStatus;
use DateTimeImmutable;

/**
 * Worker locks and heartbeats. A worker takes its name's lock for a while,
 * renews it as it goes (each renewal is a heartbeat), and releases it when it
 * stops. A lock whose time has run out can be taken by the next worker.
 *
 * @api
 */
interface WorkerStoreInterface
{
    /**
     * Take the lock if no one holds it, or its holder's time has run out.
     *
     * @throws StorageException
     */
    public function acquire(
        string $name,
        WorkerIdentity $worker,
        DateTimeImmutable $lockedUntil,
        DateTimeImmutable $now,
    ): bool;

    /**
     * @throws StorageException
     */
    public function find(string $name): ?WorkerStatus;

    /**
     * Give up the lock, leaving a last heartbeat.
     *
     * @throws StorageException
     */
    public function release(string $name, string $owner, DateTimeImmutable $now): void;

    /**
     * Extend the lock and write a heartbeat. False when the lock is no longer
     * this owner's.
     *
     * @throws StorageException
     */
    public function renew(string $name, string $owner, DateTimeImmutable $lockedUntil, DateTimeImmutable $now): bool;
}
