<?php

declare(strict_types=1);

namespace Contenir\Queue\Worker;

use Contenir\Queue\Clock\Stopwatch;
use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Storage\WorkerStoreInterface;
use Psr\Clock\ClockInterface;

/**
 * Runs a few due jobs at the end of a web request, so a site whose cron entry
 * is missing still gets its background work done, more slowly.
 *
 * Call it after the response has been sent:
 *
 *     if (function_exists('fastcgi_finish_request')) {
 *         fastcgi_finish_request();
 *     }
 *     $container->get(Drainer::class)->drain();
 *
 * It does nothing while the worker's heartbeat is fresh.
 *
 * @api
 */
final readonly class Drainer
{
    public function __construct(
        private JobRunner $runner,
        private Housekeeping $housekeeping,
        private WorkerStoreInterface $workers,
        private ClockInterface $clock,
        private DrainOptions $options,
    ) {}

    /**
     * Run due jobs within the limits, and return how many ran.
     *
     * @throws StorageException
     */
    public function drain(?WorkerIdentity $identity = null): int
    {
        $stopwatch = new Stopwatch($this->clock);
        $age       = $this->workers->find($this->options->workerName)?->heartbeatAge($stopwatch->startedAt);
        if (null !== $age && $age <= $this->options->skipWhenHeartbeatWithin) {
            return 0;
        }

        $identity ??= WorkerIdentity::current();
        $this->housekeeping->recoverStale($this->options->staleAfterSeconds);

        $ran = 0;
        while ($ran < $this->options->maxJobs && $stopwatch->elapsed() < $this->options->maxSeconds) {
            if (null === $this->runner->runNext([], $identity->lock)) {
                break;
            }

            ++$ran;
        }

        return $ran;
    }
}
