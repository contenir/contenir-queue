<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Schedule;

use Contenir\Queue\Job\Job;
use Contenir\Queue\QueueInterface;
use Contenir\Queue\Schedule\Frequency;
use Contenir\Queue\Schedule\Schedule;
use Contenir\Queue\Schedule\Scheduler;
use Contenir\Queue\Schedule\Schedules;
use Contenir\Queue\Schedule\ScheduleState;
use Contenir\Queue\Storage\ScheduleStoreInterface;
use Contenir\Queue\Tests\TestAsset\Clock\MutableClock;
use Contenir\Queue\Tests\TestAsset\Log\RecordingLogger;
use Contenir\Queue\Tests\TestAsset\Worker\WorkerKit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;
use function count;

use const NAN;

#[Group('unit')]
final class SchedulerTest extends TestCase
{
    /**
     * @return array<string, array{callable(WorkerKit, Job): void, int}>
     */
    public static function lastJobProvider(): array
    {
        return [
            'still waiting' => [static function (WorkerKit $kit, Job $job): void {}, 0],
            'still running' => [
                static function (WorkerKit $kit, Job $job): void {
                    $kit->jobs->claimNext([], 'w', $kit->clock->now());
                },
                0,
            ],
            'done'          => [
                static function (WorkerKit $kit, Job $job): void {
                    $claimed = $kit->jobs->claimNext([], 'w', $kit->clock->now());
                    self::assertNotNull($claimed);
                    $kit->jobs->complete($claimed, $kit->clock->now());
                },
                1,
            ],
            'failed'        => [
                static function (WorkerKit $kit, Job $job): void {
                    $claimed = $kit->jobs->claimNext([], 'w', $kit->clock->now());
                    self::assertNotNull($claimed);
                    $kit->jobs->fail($claimed, 'x', $kit->clock->now());
                },
                1,
            ],
        ];
    }

    private static function finish(WorkerKit $kit): void
    {
        $job = $kit->jobs->claimNext([], 'w', $kit->clock->now());
        self::assertNotNull($job);
        $kit->jobs->complete($job, $kit->clock->now());
    }

    private static function kit(Schedule ...$schedules): WorkerKit
    {
        $kit = new WorkerKit();
        foreach ($schedules as $schedule) {
            $kit->schedules->add($schedule);
        }

        return $kit;
    }

    private static function schedules(string $name): Schedules
    {
        $schedules = new Schedules();
        $schedules->add(new Schedule($name, 'test.handler', Frequency::everyMinutes(1)));

        return $schedules;
    }

    #[Test]
    public function aBrokenScheduleIsLoggedAndTheOthersStillRun(): void
    {
        $kit = self::kit(
            new Schedule('never', 'test.handler', Frequency::cron('0 0 30 2 *')),
            new Schedule('nan', 'test.handler', Frequency::everyMinutes(1), ['n' => NAN]),
            new Schedule('sync', 'test.handler', Frequency::everyMinutes(1)),
        );

        $queued = $kit->scheduler->tick();

        static::assertSame(
            [
                1,
                [
                    'error: Schedule never cannot run: The cron expression "0 0 30 2 *" never falls due',
                    'error: Schedule nan cannot run: Inf and NaN cannot be JSON encoded',
                    'info: Schedule sync queued job 1',
                ],
            ],
            [$queued, $kit->logger->lines],
        );
    }

    #[Test]
    public function aCronScheduleWaitsForItsFirstMatch(): void
    {
        $kit = self::kit(new Schedule('nightly', 'test.handler', Frequency::cron('30 9 * * *')));

        $before = $kit->scheduler->tick();
        $kit->clock->advance(30 * 60);

        static::assertSame([0, 1], [$before, $kit->scheduler->tick()]);
    }

    #[Test]
    public function anIntervalScheduleRunsAtOnceTheFirstTime(): void
    {
        $kit = self::kit(new Schedule('sync', 'test.handler', Frequency::everyMinutes(5), ['all' => true], 'mail'));

        $queued = $kit->scheduler->tick();
        $job    = $kit->jobs->find(1);

        static::assertSame(
            [1, 'test.handler', '{"all":true}', 'mail', '09:05:00', '09:00:00'],
            [
                $queued,
                $job?->jobClass,
                $job?->encodedPayload,
                $job?->queue,
                $kit->scheduleStore->states['sync']->nextRunAt->format('H:i:s'),
                $kit->scheduleStore->states['sync']->lastRunAt?->format('H:i:s'),
            ],
        );
    }

    #[Test]
    public function aRunAnotherProcessTookIsLeftToIt(): void
    {
        $clock = new MutableClock();
        $store = $this->createStub(ScheduleStoreInterface::class);
        $store->method('states')->willReturn(['sync' => new ScheduleState('sync', $clock->now())]);
        $store->method('advance')->willReturn(false);
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::never())->method('push');

        $queued = (new Scheduler(self::schedules('sync'), $store, $queue, $clock, new RecordingLogger()))->tick();

        static::assertSame(0, $queued);
    }

    #[Test]
    #[DataProvider('lastJobProvider')]
    public function aRunIsSkippedWhileTheLastOneIsUnfinished(callable $lastJob, int $expected): void
    {
        $kit = self::kit(new Schedule('sync', 'test.handler', Frequency::everyMinutes(5)));
        $kit->scheduler->tick();
        $job = $kit->jobs->find(1);
        self::assertNotNull($job);
        $lastJob($kit, $job);
        $kit->clock->advance(300);

        static::assertSame([$expected, '09:10:00'], [
            $kit->scheduler->tick(),
            $kit->scheduleStore->states['sync']->nextRunAt->format('H:i:s'),
        ]);
    }

    #[Test]
    public function aRunOfAJobThatWasDeletedIsNotSkipped(): void
    {
        $kit = self::kit(new Schedule('sync', 'test.handler', Frequency::everyMinutes(5)));
        $kit->scheduler->tick();
        $kit->jobs->jobs = [];
        $kit->clock->advance(300);

        static::assertSame(1, $kit->scheduler->tick());
    }

    #[Test]
    public function aScheduleAnotherProcessStartedIsLeftToIt(): void
    {
        $store = $this->createStub(ScheduleStoreInterface::class);
        $store->method('states')->willReturn([]);
        $store->method('start')->willReturn(false);
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::never())->method('push');

        $queued = (new Scheduler(self::schedules('sync'), $store, $queue, new MutableClock(), new RecordingLogger()))
            ->tick();

        static::assertSame(0, $queued);
    }

    #[Test]
    public function aScheduleRunsAgainWhenDue(): void
    {
        $kit = self::kit(new Schedule('sync', 'test.handler', Frequency::everyMinutes(5)));
        $kit->scheduler->tick();
        self::finish($kit);
        $kit->clock->advance(300);

        static::assertSame([1, 2], [$kit->scheduler->tick(), count($kit->jobs->jobs)]);
    }

    #[Test]
    public function aSkippedRunIsRecordedAndLogged(): void
    {
        $kit = self::kit(new Schedule('sync', 'test.handler', Frequency::everyMinutes(5)));
        $kit->scheduler->tick();
        $kit->clock->advance(300);
        $kit->scheduler->tick();

        static::assertSame(
            [
                [[1, '09:00:00'], [null, '09:05:00']],
                [
                    'info: Schedule sync queued job 1',
                    'warning: Schedule sync skipped: its last run (job 1) has not finished',
                ],
            ],
            [
                array_map(
                    static fn($run): array => [$run->jobId, $run->dueAt->format('H:i:s')],
                    $kit->scheduleStore->runs,
                ),
                $kit->logger->lines,
            ],
        );
    }

    #[Test]
    public function nothingRunsBeforeItIsDue(): void
    {
        $kit = self::kit(new Schedule('sync', 'test.handler', Frequency::everyMinutes(5)));
        $kit->scheduler->tick();
        $kit->clock->advance(299);

        static::assertSame([0, 1], [$kit->scheduler->tick(), count($kit->jobs->jobs)]);
    }

    #[Test]
    public function returnsHowManyJobsItQueued(): void
    {
        $kit = self::kit(
            new Schedule('a', 'test.handler', Frequency::everyMinutes(1)),
            new Schedule('b', 'test.handler', Frequency::everyMinutes(1)),
        );

        static::assertSame([2, [1, 2]], [
            $kit->scheduler->tick(),
            array_values(array_map(
                static fn(Job $job): int => $job->id,
                $kit->jobs->jobs,
            )),
        ]);
    }
}
