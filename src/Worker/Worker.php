<?php

declare(strict_types=1);

namespace Contenir\Queue\Worker;

use Contenir\Queue\Clock\Stopwatch;
use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Schedule\Scheduler;
use Contenir\Queue\Storage\WorkerStoreInterface;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Takes the worker lock, then runs due jobs one at a time until a limit is
 * reached or it is stopped. Each turn renews the lock, which is the heartbeat
 * a host checks to know background jobs are running, and queues the jobs of
 * any schedules that have fallen due.
 *
 * Cron runs it every minute with a time limit just under a minute. It stops
 * as soon as nothing is due, or at once if another run holds the lock. Under a
 * supervisor it runs with no time limit, waits when nothing is due, and stops
 * at its memory limit or on {@see stop()}.
 *
 * @api
 */
final class Worker
{
    private bool $stopping = false;

    /**
     * @mago-expect lint:excessive-parameter-list The worker coordinates these seven; its factory builds it.
     */
    public function __construct(
        private readonly JobRunner $runner,
        private readonly Housekeeping $housekeeping,
        private readonly WorkerStoreInterface $workers,
        private readonly Scheduler $scheduler,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ProcessInterface $process,
    ) {}

    private static function lockUntil(DateTimeImmutable $now, WorkerOptions $options): DateTimeImmutable
    {
        return $now->modify("+{$options->lockSeconds} seconds");
    }

    /**
     * @throws StorageException
     */
    public function run(WorkerOptions $options, ?WorkerIdentity $identity = null): WorkerReport
    {
        $identity       ??= WorkerIdentity::current();
        $this->stopping = false;
        $stopwatch      = new Stopwatch($this->clock);
        $context        = ['name' => $options->name, 'host' => $identity->host, 'pid' => $identity->pid];

        $acquired = $this->workers->acquire(
            $options->name,
            $identity,
            self::lockUntil($stopwatch->startedAt, $options),
            $stopwatch->startedAt,
        );
        if (! $acquired) {
            $this->logger->info('Worker {name} is already running, so {host}:{pid} did not start', $context);

            return new WorkerReport(StopReason::AlreadyRunning);
        }

        $this->logger->info('Worker {name} started on {host}:{pid}', $context);

        try {
            $recovered = $this->housekeeping->recoverStale($options->staleAfterSeconds);
            $pruned    = $this->housekeeping->pruneDone($options->pruneAfterDays);
            [$reason, $ran] = $this->work($options, $identity, $stopwatch);
        } finally {
            $this->workers->release($options->name, $identity->lock, $this->clock->now());
        }

        $this->logger->info('Worker {name} stopped ({reason}) after {jobs} jobs', [
            ...$context,
            'reason' => $reason->value,
            'jobs'   => $ran,
        ]);

        return new WorkerReport($reason, $ran, $recovered, $pruned);
    }

    /**
     * Ask a running worker to stop once its current job is done. Safe to call
     * from a signal handler.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    private function stopReason(WorkerOptions $options, Stopwatch $stopwatch, int $ran): ?StopReason
    {
        return match (true) {
            $this->stopping => StopReason::Stopped,
            $options->maxJobs > 0 && $ran >= $options->maxJobs => StopReason::MaxJobs,
            $options->maxSeconds > 0 && $stopwatch->elapsed() >= $options->maxSeconds => StopReason::TimeUp,
            $options->memoryLimitBytes > 0 && $this->process->memoryUsage() >= $options->memoryLimitBytes
                => StopReason::MemoryLimit,
            default => null,
        };
    }

    /**
     * @return array{StopReason, int}
     *
     * @throws StorageException
     */
    private function work(WorkerOptions $options, WorkerIdentity $identity, Stopwatch $stopwatch): array
    {
        $ran = 0;
        while (true) {
            $reason = $this->stopReason($options, $stopwatch, $ran);
            if (null !== $reason) {
                return [$reason, $ran];
            }

            $now = $this->clock->now();
            if (! $this->workers->renew($options->name, $identity->lock, self::lockUntil($now, $options), $now)) {
                $this->logger->warning('Worker {name} lost its lock to another worker', ['name' => $options->name]);

                return [StopReason::LockLost, $ran];
            }

            $this->scheduler->tick();
            if (null === $this->runner->runNext($options->queues, $identity->lock)) {
                if ($options->maxSeconds > 0) {
                    return [StopReason::QueueEmpty, $ran];
                }

                $this->process->sleep($options->sleepSeconds);

                continue;
            }

            ++$ran;
        }
    }
}
