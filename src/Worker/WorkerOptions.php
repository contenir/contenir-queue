<?php

declare(strict_types=1);

namespace Contenir\Queue\Worker;

use InvalidArgumentException;

/**
 * How long and how a worker runs.
 *
 * - `maxSeconds`: stop taking new jobs after this long; 0 runs until stopped
 *   (a long-running worker under a supervisor).
 * - `maxJobs`: stop after this many jobs; 0 for no limit.
 * - `sleepSeconds`: the wait between looks at an empty queue.
 * - `memoryLimitBytes`: stop once the process uses this much; 0 for no limit.
 * - `lockSeconds`: how long the worker lock lasts between renewals. A worker
 *   that dies keeps others out for at most this long.
 * - `staleAfterSeconds`: a running job locked longer than this is taken to
 *   belong to a worker that died, and is retried.
 * - `pruneAfterDays`: done jobs older than this are deleted; 0 keeps them.
 *
 * @api
 */
final readonly class WorkerOptions
{
    public const string DEFAULT_NAME = 'default';

    /**
     * @param list<string> $queues The queues to take jobs from; empty means all.
     *
     * @mago-expect lint:excessive-parameter-list Named options, each with a default.
     */
    public function __construct(
        public string $name = self::DEFAULT_NAME,
        public array $queues = [],
        public int $maxSeconds = 50,
        public int $maxJobs = 0,
        public int $sleepSeconds = 5,
        public int $memoryLimitBytes = 128 * 1024 * 1024,
        public int $lockSeconds = 300,
        public int $staleAfterSeconds = 3600,
        public int $pruneAfterDays = 7,
    ) {
        if ('' === $name) {
            throw new InvalidArgumentException('A worker needs a name');
        }

        if ($lockSeconds < 1 || $staleAfterSeconds < 1 || $sleepSeconds < 1) {
            throw new InvalidArgumentException('The lock, stale and sleep times must be at least one second');
        }

        if ($maxSeconds < 0 || $maxJobs < 0 || $memoryLimitBytes < 0 || $pruneAfterDays < 0) {
            throw new InvalidArgumentException('Worker limits cannot be negative');
        }
    }

    /**
     * These options with others replacing some of them, as a command line
     * overrides configuration.
     *
     * @param array{
     *     name?: string,
     *     queues?: list<string>,
     *     maxSeconds?: int,
     *     maxJobs?: int,
     *     sleepSeconds?: int,
     *     memoryLimitBytes?: int,
     * } $changes
     */
    public function with(array $changes): self
    {
        return new self(
            $changes['name'] ?? $this->name,
            $changes['queues'] ?? $this->queues,
            $changes['maxSeconds'] ?? $this->maxSeconds,
            $changes['maxJobs'] ?? $this->maxJobs,
            $changes['sleepSeconds'] ?? $this->sleepSeconds,
            $changes['memoryLimitBytes'] ?? $this->memoryLimitBytes,
            $this->lockSeconds,
            $this->staleAfterSeconds,
            $this->pruneAfterDays,
        );
    }
}
