<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Worker;

use Contenir\Queue\Job\JobState;
use Contenir\Queue\Tests\TestAsset\Worker\WorkerKit;
use Contenir\Queue\Worker\Housekeeping;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;

#[Group('unit')]
final class HousekeepingTest extends TestCase
{
    #[Test]
    public function deletesDoneJobsOlderThanTheGivenDays(): void
    {
        $kit = new WorkerKit();
        $ids = $kit->push(2);
        foreach ($ids as $_) {
            $job = $kit->jobs->claimNext([], 'w', $kit->clock->now());
            self::assertNotNull($job);
            $kit->jobs->complete($job, $kit->clock->now());
            $kit->clock->advance(86_400);
        }

        $kit->clock->advance(86_400 * 6);

        static::assertSame(
            [1, ['info: Deleted 1 done jobs older than 7 days'], [false, true]],
            [
                $kit->housekeeping->pruneDone(7),
                $kit->logger->lines,
                array_map(static fn(int $id): bool => null !== $kit->jobs->find($id), $ids),
            ],
        );
    }

    #[Test]
    public function deletingNothingLogsNothing(): void
    {
        $kit = new WorkerKit();

        static::assertSame([0, 0], [$kit->housekeeping->pruneDone(7), count($kit->logger->lines)]);
    }

    #[Test]
    public function recoversAtMostABatchAtATime(): void
    {
        $kit = new WorkerKit();
        $kit->push(Housekeeping::STALE_BATCH + 1);
        for ($i = 0; $i <= Housekeeping::STALE_BATCH; ++$i) {
            $kit->jobs->claimNext([], 'dead', $kit->clock->now());
        }

        $kit->clock->advance(61);

        static::assertSame(Housekeeping::STALE_BATCH, $kit->housekeeping->recoverStale(60));
    }

    #[Test]
    public function retriesJobsLockedLongerThanTheStaleTime(): void
    {
        $kit = new WorkerKit();
        [$stale, $fresh] = $kit->push(2);
        $kit->jobs->claimNext([], 'dead', $kit->clock->now());
        $kit->clock->advance(60);
        $kit->jobs->claimNext([], 'alive', $kit->clock->now());
        $kit->clock->advance(1);

        $recovered = $kit->housekeeping->recoverStale(60);

        static::assertSame(
            [1, JobState::Waiting, Housekeeping::STALE_ERROR, JobState::Running],
            [
                $recovered,
                $kit->jobs->find($stale)?->state,
                $kit->jobs->find($stale)?->lastError,
                $kit->jobs->find($fresh)?->state,
            ],
        );
    }

    #[Test]
    public function zeroDaysKeepsEverything(): void
    {
        $kit = new WorkerKit();
        [$id] = $kit->push();
        $job = $kit->jobs->claimNext([], 'w', $kit->clock->now());
        self::assertNotNull($job);
        $kit->jobs->complete($job, $kit->clock->now());
        $kit->clock->advance(86_400 * 365);

        static::assertSame([0, true], [$kit->housekeeping->pruneDone(0), null !== $kit->jobs->find($id)]);
    }
}
