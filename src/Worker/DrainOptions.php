<?php

declare(strict_types=1);

namespace Contenir\Queue\Worker;

use InvalidArgumentException;

/**
 * How much a web request drains after its response has been sent.
 *
 * - `maxJobs`, `maxSeconds`: run at most this many jobs, and start none after
 *   this long.
 * - `workerName`, `skipWhenHeartbeatWithin`: do nothing while that worker has
 *   written a heartbeat this recently, since cron is running it.
 * - `staleAfterSeconds`: as for the worker, a running job locked longer than
 *   this is retried.
 *
 * @api
 */
final readonly class DrainOptions
{
    public function __construct(
        public int $maxJobs = 3,
        public float $maxSeconds = 2.0,
        public string $workerName = WorkerOptions::DEFAULT_NAME,
        public int $skipWhenHeartbeatWithin = 120,
        public int $staleAfterSeconds = 3600,
    ) {
        if ($maxJobs < 0 || $maxSeconds < 0 || $skipWhenHeartbeatWithin < 0 || $staleAfterSeconds < 1) {
            throw new InvalidArgumentException(
                'Drain limits cannot be negative, and the stale time must be at least one second',
            );
        }
    }
}
