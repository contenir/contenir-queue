<?php

declare(strict_types=1);

namespace Contenir\Queue\Monitor;

use DateTimeImmutable;

/**
 * Whether background jobs are running, for a host's update check and
 * dashboard: the worker's last heartbeat, and what is waiting or has failed.
 *
 * The extensions plan counts the queue as configured while the worker has
 * beaten in the last 10 minutes, and as stopped once its heartbeat is over an
 * hour old.
 *
 * @api
 */
final readonly class QueueHealth
{
    public const int CONFIGURED_WITHIN = 600;
    public const int STOPPED_AFTER     = 3600;

    /**
     * @param ?int $heartbeatAge Seconds since the worker's last heartbeat; null if it has never run.
     * @param ?int $oldestWaitingAge Seconds since the longest-waiting job was pushed; null if none is waiting.
     *
     * @mago-expect lint:excessive-parameter-list One parameter per fact a warning needs.
     */
    public function __construct(
        public ?DateTimeImmutable $heartbeatAt,
        public ?int $heartbeatAge,
        public bool $workerRunning,
        public int $waiting,
        public ?int $oldestWaitingAge,
        public int $failed,
    ) {}

    /**
     * Whether the worker has run recently enough to count as set up.
     */
    public function isConfigured(int $within = self::CONFIGURED_WITHIN): bool
    {
        return null !== $this->heartbeatAge && $this->heartbeatAge <= $within;
    }

    /**
     * Whether the worker has never run, or not for longer than the given time.
     */
    public function isStopped(int $after = self::STOPPED_AFTER): bool
    {
        return null === $this->heartbeatAge || $this->heartbeatAge > $after;
    }
}
