<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Monitor;

use Contenir\Queue\Job\JobState;
use Contenir\Queue\Monitor\JobCounts;
use Contenir\Queue\Monitor\QueueHealth;
use Contenir\Queue\Monitor\QueueMonitor;
use Contenir\Queue\Monitor\ScheduleStatus;
use Contenir\Queue\Schedule\Frequency;
use Contenir\Queue\Schedule\Schedule;
use Contenir\Queue\Schedule\ScheduleRun;
use Contenir\Queue\Schedule\Schedules;
use Contenir\Queue\Schedule\ScheduleState;
use Contenir\Queue\Storage\JobBrowserInterface;
use Contenir\Queue\Storage\ScheduleStoreInterface;
use Contenir\Queue\Tests\TestAsset\Clock\MutableClock;
use Contenir\Queue\Tests\TestAsset\Job\JobFactory;
use Contenir\Queue\Tests\TestAsset\Storage\InMemoryWorkerStore;
use Contenir\Queue\Tests\TestAsset\Worker\WorkerKit;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class QueueMonitorTest extends TestCase
{
    private MutableClock $clock;

    private JobBrowserInterface&Stub $jobs;

    private ScheduleStoreInterface&Stub $scheduleStore;

    private Schedules $schedules;

    private InMemoryWorkerStore $workers;

    #[Test]
    public function aWorkerWhoseLockRanOutIsNotRunning(): void
    {
        $this->workers->acquire('default', WorkerKit::identity(), $this->at('08:59'), $this->at('08:54'));

        static::assertFalse($this->monitor()->health()->workerRunning);
    }

    #[Test]
    public function countsJobsByState(): void
    {
        static::assertEquals(new JobCounts(4, 1, 9, 2), $this->monitor()->counts());
    }

    #[Test]
    public function deletesAJob(): void
    {
        $this->jobs->method('delete')->willReturnMap([[42, true], [43, false]]);

        static::assertSame([true, false], [$this->monitor()->delete(42), $this->monitor()->delete(43)]);
    }

    #[Test]
    public function findsAJob(): void
    {
        $job = JobFactory::running();
        $this->jobs->method('find')->willReturnMap([[42, $job]]);

        static::assertSame($job, $this->monitor()->job(42));
    }

    #[Test]
    public function healthOfAQueueThatNeverRan(): void
    {
        static::assertEquals(new QueueHealth(null, null, false, 4, null, 2), $this->monitor()->health());
    }

    #[Test]
    public function healthReportsTheHeartbeatAndWhatIsWaiting(): void
    {
        $this->workers->acquire('default', WorkerKit::identity(), $this->at('09:05'), $this->at('08:58'));
        $this->jobs->method('oldestWaitingSince')->willReturn($this->at('06:00'));

        static::assertEquals(
            new QueueHealth($this->at('08:58'), 120, true, 4, 10_800, 2),
            $this->monitor()->health(),
        );
    }

    #[Test]
    public function healthWatchesTheNamedWorker(): void
    {
        $this->workers->acquire('nightly', WorkerKit::identity(), $this->at('09:05'), $this->at('08:59'));

        static::assertSame(
            [60, null],
            [$this->monitor()->health('nightly')->heartbeatAge, $this->monitor()->health()->heartbeatAge],
        );
    }

    #[Test]
    public function listsAScheduleRuns(): void
    {
        $run = new ScheduleRun(1, 'sync', $this->at('09:00'), $this->at('09:00'), 3, JobState::Done);
        $this->scheduleStore->method('runs')->willReturnMap([['sync', 20, [$run]], ['sync', 5, []]]);

        static::assertSame([[$run], []], [$this->monitor()->runs('sync'), $this->monitor()->runs('sync', 5)]);
    }

    #[Test]
    public function listsJobsByState(): void
    {
        $job = JobFactory::running();
        $this->jobs
            ->method('findByState')
            ->willReturnMap([
                [JobState::Failed, 50, 0, [$job]],
                [JobState::Failed, 10, 20, []],
            ]);

        static::assertSame([[$job], []], [
            $this->monitor()->jobs(JobState::Failed),
            $this->monitor()->jobs(JobState::Failed, 10, 20),
        ]);
    }

    #[Test]
    public function retriesAFailedJobFromNow(): void
    {
        $jobs = $this->createMock(JobBrowserInterface::class);
        $jobs->expects(self::once())->method('retryFailed')->with(42, $this->clock->now())->willReturn(true);
        $this->jobs = $jobs;

        static::assertTrue($this->monitor()->retry(42));
    }

    #[Test]
    public function showsEachScheduleWithItsLastAndNextRun(): void
    {
        $this->schedules->add(new Schedule('sync', 'sync.job', Frequency::everyMinutes(5), queue: 'mail'));
        $this->schedules->add(new Schedule('nightly', 'nightly.job', Frequency::cron(
            '0 2 * * *',
            new DateTimeZone('Australia/Sydney'),
        )));
        $this->schedules->add(new Schedule('never', 'never.job', Frequency::cron('0 0 30 2 *')));
        $this->scheduleStore
            ->method('states')
            ->willReturn([
                'sync'    => new ScheduleState('sync', $this->at('09:05'), $this->at('09:00'), 7, JobState::Running),
                'retired' => new ScheduleState('retired', $this->at('09:05')),
            ]);

        static::assertEquals(
            [
                new ScheduleStatus(
                    'sync',
                    'sync.job',
                    'mail',
                    'every 5 minutes',
                    $this->at('09:00'),
                    $this->at('09:05'),
                    7,
                    JobState::Running,
                ),
                new ScheduleStatus(
                    'nightly',
                    'nightly.job',
                    'default',
                    '0 2 * * * (Australia/Sydney)',
                    null,
                    $this->at('15:00'),
                    null,
                    null,
                ),
                new ScheduleStatus('never', 'never.job', 'default', '0 0 30 2 * (UTC)', null, null, null, null),
            ],
            $this->monitor()->schedules(),
        );
    }

    protected function setUp(): void
    {
        $this->clock         = new MutableClock();
        $this->jobs          = $this->createStub(JobBrowserInterface::class);
        $this->scheduleStore = $this->createStub(ScheduleStoreInterface::class);
        $this->schedules     = new Schedules();
        $this->workers       = new InMemoryWorkerStore();
        $this->jobs->method('countByState')->willReturn(['waiting' => 4, 'running' => 1, 'done' => 9, 'failed' => 2]);
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-10-19 {$time}:00", new DateTimeZone('UTC'));
    }

    private function monitor(): QueueMonitor
    {
        return new QueueMonitor($this->jobs, $this->scheduleStore, $this->schedules, $this->workers, $this->clock);
    }
}
