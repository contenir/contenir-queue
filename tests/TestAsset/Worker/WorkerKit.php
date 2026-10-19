<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Worker;

use Closure;
use Contenir\Queue\Job\Backoff;
use Contenir\Queue\Job\Job;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Queue;
use Contenir\Queue\Schedule\Scheduler;
use Contenir\Queue\Schedule\Schedules;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Tests\TestAsset\Clock\MutableClock;
use Contenir\Queue\Tests\TestAsset\Container\ArrayContainer;
use Contenir\Queue\Tests\TestAsset\Job\CallbackHandler;
use Contenir\Queue\Tests\TestAsset\Log\RecordingLogger;
use Contenir\Queue\Tests\TestAsset\Storage\InMemoryJobStore;
use Contenir\Queue\Tests\TestAsset\Storage\InMemoryScheduleStore;
use Contenir\Queue\Tests\TestAsset\Storage\InMemoryWorkerStore;
use Contenir\Queue\Worker\Drainer;
use Contenir\Queue\Worker\DrainOptions;
use Contenir\Queue\Worker\Housekeeping;
use Contenir\Queue\Worker\Worker;
use Contenir\Queue\Worker\WorkerIdentity;

/**
 * A worker, a drainer and everything they work on, in memory: the jobs, the
 * worker rows, a clock that moves only when told, and a handler that takes a
 * set number of seconds per job.
 */
final class WorkerKit
{
    public readonly MutableClock $clock;

    public readonly RecordingLogger $logger;

    public readonly InMemoryWorkerStore $workers;

    public readonly CallbackHandler $handler;

    public readonly FakeProcess $process;

    public readonly JobRunner $runner;

    public readonly Housekeeping $housekeeping;

    public readonly Schedules $schedules;

    public readonly InMemoryScheduleStore $scheduleStore;

    public readonly Scheduler $scheduler;

    /**
     * @param ?Closure(WorkerKit): void $onSleep
     */
    public function __construct(
        public readonly JobStoreInterface $jobs = new InMemoryJobStore(),
        int|float $secondsPerJob = 0,
        ?Closure $onSleep = null,
    ) {
        $this->clock   = new MutableClock();
        $this->logger  = new RecordingLogger();
        $this->workers = new InMemoryWorkerStore();
        $clock         = $this->clock;
        $this->handler = new CallbackHandler(static function (Job $job) use ($clock, $secondsPerJob): void {
            $clock->advance($secondsPerJob);
        });
        $this->process = new FakeProcess(
            $this->clock,
            onSleep: null === $onSleep ? null : fn() => $onSleep($this),
        );
        $this->runner = new JobRunner(
            new ArrayContainer(['test.handler' => $this->handler]),
            $this->jobs,
            new Backoff(),
            $this->clock,
            $this->logger,
        );
        $this->schedules     = new Schedules();
        $this->scheduleStore = new InMemoryScheduleStore($this->jobs);
        $this->scheduler     = new Scheduler(
            $this->schedules,
            $this->scheduleStore,
            new Queue($this->jobs, $this->clock),
            $this->clock,
            $this->logger,
        );
        $this->housekeeping = new Housekeeping(
            $this->jobs,
            $this->scheduleStore,
            $this->runner,
            $this->clock,
            $this->logger,
        );
    }

    public static function identity(): WorkerIdentity
    {
        return new WorkerIdentity('web1', 4321, 'web1:4321:abcd1234');
    }

    public function drainer(DrainOptions $options = new DrainOptions()): Drainer
    {
        return new Drainer(
            $this->runner,
            $this->housekeeping,
            $this->workers,
            $this->scheduler,
            $this->clock,
            $options,
        );
    }

    /**
     * Push jobs for the test handler, due now.
     *
     * @return list<int>
     */
    public function push(int $count = 1, string $queue = 'default'): array
    {
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $ids[] = $this->jobs->push($queue, 'test.handler', '[]', $this->clock->now(), $this->clock->now());
        }

        return $ids;
    }

    public function worker(): Worker
    {
        return new Worker(
            $this->runner,
            $this->housekeeping,
            $this->workers,
            $this->scheduler,
            $this->clock,
            $this->logger,
            $this->process,
        );
    }
}
