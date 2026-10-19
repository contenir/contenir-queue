<?php

declare(strict_types=1);

namespace Contenir\Queue;

use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Job\JobHandlerInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

/**
 * Where hosts and add-ons put work to run in the background.
 *
 * @api
 */
interface QueueInterface
{
    public const string DEFAULT_QUEUE = 'default';

    /**
     * Store a job to run as soon as a worker is free, or no sooner than
     * `$notBefore`, and return its id. The job is in the database when this
     * returns, so it is never lost if nothing is running yet.
     *
     * @param class-string<JobHandlerInterface>|string $jobClass The handler's name in the container.
     * @param array<array-key, mixed> $payload Anything JSON can carry.
     *
     * @throws InvalidArgumentException When the job class or queue name is empty or too long.
     * @throws JsonException When the payload cannot be encoded as JSON.
     * @throws StorageException
     */
    public function push(
        string $jobClass,
        array $payload = [],
        string $queue = self::DEFAULT_QUEUE,
        ?DateTimeImmutable $notBefore = null,
    ): int;
}
