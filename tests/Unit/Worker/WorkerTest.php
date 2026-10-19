<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Worker;

use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Job\JobState;
use Contenir\Queue\Schedule\Frequency;
use Contenir\Queue\Schedule\Schedule;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Tests\TestAsset\Storage\InMemoryJobStore;
use Contenir\Queue\Tests\TestAsset\Worker\WorkerKit;
use Contenir\Queue\Worker\Housekeeping;
use Contenir\Queue\Worker\StopReason;
use Contenir\Queue\Worker\WorkerOptions;
use Contenir\Queue\Worker\WorkerReport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function gethostname;

#[Group('unit')]
final class WorkerTest extends TestCase
{
    #[Test]
    public function aDaemonRunsUntilStopped(): void
    {
        $sleeps = 0;
        $kit    = new WorkerKit(onSleep: static function (WorkerKit $kit) use (&$sleeps, &$worker): void {
            if (++$sleeps === 3) {
                $worker->stop();
            }
        });
        $worker = $kit->worker();

        $report = $worker->run(new WorkerOptions(
            maxSeconds: 0,
            sleepSeconds: 30,
        ), WorkerKit::identity());

        static::assertSame([StopReason::Stopped, [30, 30, 30]], [$report->reason, $kit->process->sleeps]);
    }

    #[Test]
    public function aJobThatRunsPastTheLimitStillFinishes(): void
    {
        $kit = new WorkerKit(secondsPerJob: 60);
        $ids = $kit->push(2);

        $report = $kit->worker()->run(new WorkerOptions(maxSeconds: 50), WorkerKit::identity());

        static::assertSame(
            [1, JobState::Done, JobState::Waiting],
            [$report->jobsRun, $kit->jobs->find($ids[0])?->state, $kit->jobs->find($ids[1])?->state],
        );
    }

    #[Test]
    public function aNewRunForgetsAnEarlierStop(): void
    {
        $kit    = new WorkerKit();
        $worker = $kit->worker();
        $kit->push();
        $worker->stop();

        $report = $worker->run(new WorkerOptions(maxJobs: 1), WorkerKit::identity());

        static::assertSame(StopReason::MaxJobs, $report->reason);
    }

    #[Test]
    public function doesNotStartWhileAnotherWorkerHoldsTheLock(): void
    {
        $kit = new WorkerKit();
        $kit->workers->hijack('default', 'other-lock', $kit->clock->now()->modify('+1 minute'));
        $ids = $kit->push();

        $report = $kit->worker()->run(new WorkerOptions(), WorkerKit::identity());

        static::assertEquals(
            [
                new WorkerReport(StopReason::AlreadyRunning),
                JobState::Waiting,
                ['info: Worker default is already running, so web1:4321 did not start'],
            ],
            [$report, $kit->jobs->find($ids[0])?->state, $kit->logger->lines],
        );
    }

    #[Test]
    public function identifiesItselfAsThisProcessByDefault(): void
    {
        $kit = new WorkerKit(new InMemoryJobStore());

        $kit->worker()->run(new WorkerOptions(maxSeconds: 1));

        static::assertSame(gethostname(), $kit->workers->find('default')?->host);
    }

    #[Test]
    public function keepsLookingForWorkUntilTheTimeLimit(): void
    {
        $kit = new WorkerKit();

        $kit->worker()->run(new WorkerOptions(
            maxSeconds: 12,
            sleepSeconds: 5,
        ), WorkerKit::identity());

        static::assertSame([5, 5, 2], $kit->process->sleeps);
    }

    #[Test]
    public function locksForTheConfiguredTime(): void
    {
        $kit = new WorkerKit(onSleep: static function (WorkerKit $kit) use (&$until): void {
            $until = $kit->workers->find('default')?->lockedUntil?->format('H:i:s');
        });

        $kit->worker()->run(new WorkerOptions(
            maxSeconds: 5,
            sleepSeconds: 5,
            lockSeconds: 90,
        ), WorkerKit::identity());

        static::assertSame('09:01:30', $until);
    }

    #[Test]
    public function logsItsStartAndStop(): void
    {
        $kit = new WorkerKit();
        $kit->push();

        $kit->worker()->run(new WorkerOptions(maxJobs: 1), WorkerKit::identity());

        static::assertSame(
            [
                'info: Worker default started on web1:4321',
                'info: Job 1 (test.handler) done',
                'info: Worker default stopped (job limit) after 1 jobs',
            ],
            $kit->logger->lines,
        );
    }

    #[Test]
    public function noMemoryLimitIgnoresMemory(): void
    {
        $kit                  = new WorkerKit();
        $kit->process->memory = 1_000_000_000;
        $kit->push();

        $report = $kit->worker()->run(new WorkerOptions(
            maxJobs: 1,
            memoryLimitBytes: 0,
        ), WorkerKit::identity());

        static::assertSame(StopReason::MaxJobs, $report->reason);
    }

    #[Test]
    public function queuesAndRunsDueSchedulesOnEachTurn(): void
    {
        $kit = new WorkerKit();
        $kit->schedules->add(new Schedule('sync', 'test.handler', Frequency::everyMinutes(1)));

        $report = $kit->worker()->run(new WorkerOptions(
            maxSeconds: 130,
            sleepSeconds: 5,
        ), WorkerKit::identity());

        static::assertSame(3, $report->jobsRun);
    }

    #[Test]
    public function recoversStaleJobsAndPrunesOldOnesFirst(): void
    {
        $kit = new WorkerKit();
        [$stale, $old] = $kit->push(2);
        $kit->jobs->claimNext([], 'dead-worker', $kit->clock->now());
        $finished = $kit->jobs->claimNext([], 'old-worker', $kit->clock->now());
        self::assertNotNull($finished);
        $kit->jobs->complete($finished, $kit->clock->now());
        $kit->clock->advance(8 * 86_400);

        $report = $kit->worker()->run(new WorkerOptions(maxSeconds: 1), WorkerKit::identity());

        static::assertSame(
            [1, 1, JobState::Waiting, Housekeeping::STALE_ERROR, null],
            [
                $report->recovered,
                $report->pruned,
                $kit->jobs->find($stale)?->state,
                $kit->jobs->find($stale)?->lastError,
                $kit->jobs->find($old),
            ],
        );
    }

    #[Test]
    public function releasesTheLockWhenStorageFails(): void
    {
        $jobs = $this->createStub(JobStoreInterface::class);
        $jobs->method('findStale')->willReturn([]);
        $jobs->method('claimNext')->willThrowException(StorageException::noResult());
        $kit = new WorkerKit($jobs);

        try {
            $kit->worker()->run(new WorkerOptions(), WorkerKit::identity());
            static::fail('The storage failure was not reported');
        } catch (StorageException) {
            static::assertNull($kit->workers->find('default')?->owner);
        }
    }

    #[Test]
    public function releasesTheLockWithAFinalHeartbeat(): void
    {
        $kit = new WorkerKit(secondsPerJob: 4);
        $kit->push();

        $kit->worker()->run(new WorkerOptions(maxJobs: 1), WorkerKit::identity());
        $status = $kit->workers->find('default');

        static::assertSame([null, null, '09:00:04'], [
            $status?->owner,
            $status?->lockedUntil,
            $status?->heartbeatAt?->format('H:i:s'),
        ]);
    }

    #[Test]
    public function renewsTheLockEachTurn(): void
    {
        $kit = new WorkerKit(secondsPerJob: 3);
        $kit->push(2);

        $kit->worker()->run(new WorkerOptions(
            maxSeconds: 10,
            sleepSeconds: 5,
        ), WorkerKit::identity());

        static::assertSame(['09:00:00', '09:00:03', '09:00:06'], $kit->workers->renewals);
    }

    #[Test]
    public function runsBelowTheMemoryLimit(): void
    {
        $kit                  = new WorkerKit();
        $kit->process->memory = 999;
        $kit->push();

        $report = $kit->worker()->run(new WorkerOptions(
            maxJobs: 1,
            memoryLimitBytes: 1_000,
        ), WorkerKit::identity());

        static::assertSame(StopReason::MaxJobs, $report->reason);
    }

    #[Test]
    public function runsDueJobsUntilTheTimeLimit(): void
    {
        $kit = new WorkerKit(secondsPerJob: 10);
        $ids = $kit->push(3);

        $report = $kit->worker()->run(new WorkerOptions(maxSeconds: 50), WorkerKit::identity());

        static::assertEquals(
            [new WorkerReport(StopReason::TimeUp, jobsRun: 3), [JobState::Done, JobState::Done, JobState::Done]],
            [$report, array_map(static fn(int $id): ?JobState => $kit->jobs->find($id)?->state, $ids)],
        );
    }

    #[Test]
    public function stopsAfterTheJobLimit(): void
    {
        $kit = new WorkerKit();
        $kit->push(3);

        $report = $kit->worker()->run(new WorkerOptions(maxJobs: 2), WorkerKit::identity());

        static::assertEquals(new WorkerReport(StopReason::MaxJobs, jobsRun: 2), $report);
    }

    #[Test]
    public function stopsAtTheMemoryLimit(): void
    {
        $kit                  = new WorkerKit();
        $kit->process->memory = 1_000;

        $report = $kit->worker()->run(new WorkerOptions(memoryLimitBytes: 1_000), WorkerKit::identity());

        static::assertSame(StopReason::MemoryLimit, $report->reason);
    }

    #[Test]
    public function stopsWhenAnotherWorkerTakesTheLock(): void
    {
        $kit = new WorkerKit(onSleep: static function (WorkerKit $kit): void {
            $kit->workers->hijack('default', 'other-lock', $kit->clock->now()->modify('+5 minutes'));
        });

        $report = $kit->worker()->run(new WorkerOptions(maxSeconds: 50), WorkerKit::identity());

        static::assertSame(
            [StopReason::LockLost, 'warning: Worker default lost its lock to another worker', 'other-lock'],
            [$report->reason, $kit->logger->lines[1], $kit->workers->find('default')?->owner],
        );
    }

    #[Test]
    public function takesOnlyFromItsQueues(): void
    {
        $kit  = new WorkerKit();
        $mail = $kit->push(queue: 'mail');
        $sync = $kit->push(queue: 'sync');

        $kit->worker()->run(new WorkerOptions(
            queues: ['sync'],
            maxSeconds: 1,
        ), WorkerKit::identity());

        static::assertSame(
            [JobState::Waiting, JobState::Done],
            [$kit->jobs->find($mail[0])?->state, $kit->jobs->find($sync[0])?->state],
        );
    }

    #[Test]
    public function takesTheLockWithItsIdentity(): void
    {
        $kit = new WorkerKit(onSleep: static function (WorkerKit $kit) use (&$status): void {
            $status = $kit->workers->find('nightly');
        });

        $kit->worker()->run(new WorkerOptions(
            name: 'nightly',
            maxSeconds: 1,
        ), WorkerKit::identity());

        static::assertSame(
            ['web1:4321:abcd1234', 'web1', 4321, '09:00:00'],
            [$status?->owner, $status?->host, $status?->pid, $status?->startedAt?->format('H:i:s')],
        );
    }

    #[Test]
    public function waitsAtLeastASecondNearTheTimeLimit(): void
    {
        $kit = new WorkerKit(secondsPerJob: 49.5);
        $kit->push();

        $kit->worker()->run(new WorkerOptions(
            maxSeconds: 50,
            sleepSeconds: 5,
        ), WorkerKit::identity());

        static::assertSame([1], $kit->process->sleeps);
    }
}
