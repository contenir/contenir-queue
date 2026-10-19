<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Worker;

use Contenir\Queue\Job\JobState;
use Contenir\Queue\Schedule\Frequency;
use Contenir\Queue\Schedule\Schedule;
use Contenir\Queue\Tests\TestAsset\Worker\WorkerKit;
use Contenir\Queue\Worker\DrainOptions;
use Contenir\Queue\Worker\Housekeeping;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function gethostname;

#[Group('unit')]
final class DrainerTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function heartbeatProvider(): array
    {
        return [
            'beat just now'                => [0, 0],
            'beat at the threshold'        => [120, 0],
            'beat just past the threshold' => [121, 1],
        ];
    }

    #[Test]
    public function aLimitOfNoJobsRunsNone(): void
    {
        $kit = new WorkerKit();
        $kit->push();

        static::assertSame(0, $kit->drainer(new DrainOptions(maxJobs: 0))->drain(WorkerKit::identity()));
    }

    #[Test]
    public function claimsUnderItsOwnIdentity(): void
    {
        $kit                   = new WorkerKit();
        $kit->handler->handled = [];
        $kit->push();

        $kit->drainer()->drain(WorkerKit::identity());

        static::assertSame('web1:4321:abcd1234', $kit->handler->handled[0]->lockedBy);
    }

    #[Test]
    public function identifiesItselfAsThisProcessByDefault(): void
    {
        $kit = new WorkerKit();
        $kit->push();

        $kit->drainer()->drain();

        static::assertStringStartsWith(gethostname() . ':', (string) $kit->handler->handled[0]->lockedBy);
    }

    #[Test]
    #[DataProvider('heartbeatProvider')]
    public function leavesTheJobsToAWorkerWithAFreshHeartbeat(int $age, int $expected): void
    {
        $kit = new WorkerKit();
        $kit->push();
        $kit->workers->acquire('default', WorkerKit::identity(), $kit->clock->now(), $kit->clock->now());
        $kit->clock->advance($age);

        static::assertSame($expected, $kit->drainer()->drain(WorkerKit::identity()));
    }

    #[Test]
    public function queuesDueSchedulesThenRunsThem(): void
    {
        $kit = new WorkerKit();
        $kit->schedules->add(new Schedule('sync', 'test.handler', Frequency::everyMinutes(1)));

        static::assertSame(1, $kit->drainer()->drain(WorkerKit::identity()));
    }

    #[Test]
    public function recoversStaleJobs(): void
    {
        $kit = new WorkerKit();
        [$id] = $kit->push();
        $kit->jobs->claimNext([], 'dead', $kit->clock->now());
        $kit->clock->advance(61);

        $kit->drainer(new DrainOptions(
            maxJobs: 0,
            staleAfterSeconds: 60,
        ))->drain(WorkerKit::identity());

        static::assertSame(Housekeeping::STALE_ERROR, $kit->jobs->find($id)?->lastError);
    }

    #[Test]
    public function runsDueJobsWhenNoWorkerHasEverRun(): void
    {
        $kit = new WorkerKit();
        $ids = $kit->push(2);

        $ran = $kit->drainer()->drain(WorkerKit::identity());

        static::assertSame(
            [2, JobState::Done, JobState::Done],
            [$ran, $kit->jobs->find($ids[0])?->state, $kit->jobs->find($ids[1])?->state],
        );
    }

    #[Test]
    public function startsNoJobAfterTheTimeLimit(): void
    {
        $kit = new WorkerKit(secondsPerJob: 0.9);
        $kit->push(5);

        static::assertSame(
            2,
            $kit->drainer(new DrainOptions(
                maxJobs: 5,
                maxSeconds: 1.8,
            ))->drain(WorkerKit::identity()),
        );
    }

    #[Test]
    public function stopsAtTheJobLimit(): void
    {
        $kit = new WorkerKit();
        $kit->push(5);

        static::assertSame(3, $kit->drainer()->drain(WorkerKit::identity()));
    }

    #[Test]
    public function stopsWhenNothingIsDue(): void
    {
        $kit = new WorkerKit();
        $kit->push();

        static::assertSame(1, $kit->drainer()->drain(WorkerKit::identity()));
    }

    #[Test]
    public function watchesTheConfiguredWorker(): void
    {
        $kit = new WorkerKit();
        $kit->push();
        $kit->workers->acquire('default', WorkerKit::identity(), $kit->clock->now(), $kit->clock->now());

        static::assertSame(1, $kit->drainer(new DrainOptions(workerName: 'other'))->drain(WorkerKit::identity()));
    }
}
