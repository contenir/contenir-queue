<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Integration\Storage;

use Contenir\Queue\Job\Job;
use Contenir\Queue\Job\JobState;
use Contenir\Queue\Storage\DbTime;
use Contenir\Queue\Storage\PhpDbJobStore;
use Contenir\Queue\Tests\Trait\TestDatabaseTrait;
use DateTimeImmutable;
use PhpDb\Adapter\Adapter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function strlen;

#[Group('integration')]
#[Group('repository')]
final class PhpDbJobStoreTest extends TestCase
{
    use TestDatabaseTrait;

    private PhpDbJobStore $store;

    private static function at(string $time): DateTimeImmutable
    {
        return DbTime::parse('2026-10-19 ' . (strlen($time) === 5 ? "{$time}:00" : $time));
    }

    /**
     * @param list<Job> $jobs
     *
     * @return list<int>
     */
    private static function ids(array $jobs): array
    {
        return array_map(static fn(Job $job): int => $job->id, $jobs);
    }

    #[Test]
    public function anEmptyQueueCountsNothing(): void
    {
        static::assertSame(['waiting' => 0, 'running' => 0, 'done' => 0, 'failed' => 0], $this->store->countByState());
    }

    #[Test]
    public function claimHonoursTheQueues(): void
    {
        $this->push(queue: 'mail');
        $sync = $this->push(queue: 'sync');
        $this->push(queue: 'other');

        static::assertSame(
            [$sync, 'mail', null],
            [
                $this->store->claimNext(['sync'], 'w', self::at('09:00'))?->id,
                $this->store->claimNext(['sync', 'mail'], 'w', self::at('09:00'))?->queue,
                $this->store->claimNext(['sync', 'mail'], 'w', self::at('09:00')),
            ],
        );
    }

    #[Test]
    public function claimLocksTheJobAndCountsTheAttempt(): void
    {
        $id  = $this->push();
        $job = $this->store->claimNext([], 'worker-1', self::at('09:01'));

        static::assertEquals(
            [$id, JobState::Running, 1, 'worker-1', self::at('09:01'), self::at('09:01')],
            [$job?->id, $job?->state, $job?->attempts, $job?->lockedBy, $job?->lockedAt, $job?->updatedAt],
        );
    }

    #[Test]
    public function claimLooksPastEarlierJobsThatAreAlreadyRunning(): void
    {
        $ids = [];
        for ($i = 0; $i < 11; ++$i) {
            $ids[] = $this->push();
        }

        $this->adapter->query(
            "UPDATE queue_job SET state = 'running' WHERE id < {$ids[10]}",
            Adapter::QUERY_MODE_EXECUTE,
        );

        static::assertSame($ids[10], $this->store->claimNext([], 'w', self::at('09:00'))?->id);
    }

    #[Test]
    public function claimNeverTakesAJobTwice(): void
    {
        $id = $this->push();

        static::assertSame(
            [$id, null],
            [
                $this->store->claimNext([], 'a', self::at('09:00'))?->id,
                $this->store->claimNext([], 'b', self::at('09:00')),
            ],
        );
    }

    #[Test]
    public function claimOnlyTakesWaitingJobs(): void
    {
        $this->claimed();
        $failed = $this->claimed();
        $this->store->fail($failed, 'x', self::at('09:00'));
        $done = $this->claimed();
        $this->store->complete($done, self::at('09:00'));

        static::assertNull($this->store->claimNext([], 'w', self::at('10:00')));
    }

    #[Test]
    public function claimSkipsJobsNotYetDue(): void
    {
        $this->push(due: '09:01');

        static::assertNull($this->store->claimNext([], 'w', self::at('09:00:59')));
    }

    #[Test]
    public function claimTakesAJobDueThisSecond(): void
    {
        $id = $this->push(due: '09:01');

        static::assertSame($id, $this->store->claimNext([], 'w', self::at('09:01'))?->id);
    }

    #[Test]
    public function claimTakesTheEarliestDueJobFirst(): void
    {
        $this->push(due: '09:00');
        $earlier = $this->push(due: '08:30');

        static::assertSame($earlier, $this->store->claimNext([], 'w', self::at('09:00'))?->id);
    }

    #[Test]
    public function claimTakesTheOldestOfJobsDueTogether(): void
    {
        $first = $this->push(due: '09:00');
        $this->push(due: '09:00');

        static::assertSame($first, $this->store->claimNext([], 'w', self::at('09:00'))?->id);
    }

    #[Test]
    public function completeMarksTheJobDoneAndReleasesTheLock(): void
    {
        $job = $this->claimed();

        $completed = $this->store->complete($job, self::at('09:02'));

        static::assertSame(
            [true, JobState::Done, null, null, '09:02:00', '09:02:00'],
            $this->summary($completed, $job->id),
        );
    }

    #[Test]
    public function countByStateCountsEveryState(): void
    {
        $this->claimed();
        $failed = $this->claimed();
        $this->store->fail($failed, 'x', self::at('09:00'));
        $this->push();

        static::assertSame(
            ['waiting' => 1, 'running' => 1, 'done' => 0, 'failed' => 1],
            $this->store->countByState(),
        );
    }

    #[Test]
    public function deleteRemovesAJobThatIsNotRunning(): void
    {
        $failed = $this->claimed();
        $this->store->fail($failed, 'x', self::at('09:00'));
        $running = $this->claimed();
        $waiting = $this->push();
        $other   = $this->push(due: '10:00');

        static::assertSame(
            [true, true, false, false, null, null, true, true],
            [
                $this->store->delete($waiting),
                $this->store->delete($failed->id),
                $this->store->delete($running->id),
                $this->store->delete(999),
                $this->store->find($waiting),
                $this->store->find($failed->id),
                null !== $this->store->find($running->id),
                null !== $this->store->find($other),
            ],
        );
    }

    #[Test]
    public function failMarksTheJobFailedWithTheError(): void
    {
        $job = $this->claimed();

        $failed = $this->store->fail($job, 'Boom', self::at('09:03'));
        $stored = $this->store->find($job->id);

        static::assertSame(
            [true, JobState::Failed, null, null, '09:03:00', '09:03:00', 'Boom'],
            [...$this->summary($failed, $job->id), $stored?->lastError],
        );
    }

    #[Test]
    public function findByStateListsFinishedJobsNewestFirst(): void
    {
        $new = $this->claimed();
        $this->store->fail($new, 'x', self::at('09:02'));
        $old = $this->claimed();
        $this->store->fail($old, 'x', self::at('09:01'));
        $tie = $this->claimed();
        $this->store->fail($tie, 'x', self::at('09:02'));
        $done = $this->claimed();
        $this->store->complete($done, self::at('09:03'));

        static::assertSame(
            [[$tie->id, $new->id, $old->id], [$done->id]],
            [
                self::ids($this->store->findByState(JobState::Failed, 10, 0)),
                self::ids($this->store->findByState(JobState::Done, 10, 0)),
            ],
        );
    }

    #[Test]
    public function findByStateListsRunningJobsByLockTime(): void
    {
        $first  = $this->push(due: '07:00');
        $second = $this->push(due: '07:00');
        $third  = $this->push(due: '07:00');
        $this->store->claimNext([], 'a', self::at('07:30'));
        $this->store->claimNext([], 'b', self::at('08:00'));
        $this->store->claimNext([], 'c', self::at('07:45'));

        static::assertSame([$first, $third, $second], self::ids($this->store->findByState(JobState::Running, 10, 0)));
    }

    #[Test]
    public function findByStateListsWaitingJobsByNextAttempt(): void
    {
        $later  = $this->push(due: '09:30');
        $sooner = $this->push(due: '09:10');
        $tied   = $this->push(due: '09:10');
        $this->claimed();

        static::assertSame([$sooner, $tied, $later], self::ids($this->store->findByState(JobState::Waiting, 10, 0)));
    }

    #[Test]
    public function findByStatePages(): void
    {
        $ids = [$this->push(due: '09:01'), $this->push(due: '09:02'), $this->push(due: '09:03')];

        static::assertSame(
            [[$ids[0], $ids[1]], [$ids[2]]],
            [
                self::ids($this->store->findByState(JobState::Waiting, 2, 0)),
                self::ids($this->store->findByState(JobState::Waiting, 2, 2)),
            ],
        );
    }

    #[Test]
    public function findReturnsNullForAnUnknownJob(): void
    {
        static::assertNull($this->store->find(999));
    }

    #[Test]
    public function findStaleListsRunningJobsLockedBeforeTheCutOff(): void
    {
        $this->push(due: '07:00');
        $this->push(due: '07:00');
        $this->push(due: '07:00');
        $old    = $this->store->claimNext([], 'a', self::at('08:00'));
        $edge   = $this->store->claimNext([], 'b', self::at('08:30'));
        $recent = $this->store->claimNext([], 'c', self::at('08:59'));
        self::assertNotNull($recent);
        $this->store->complete($recent, self::at('08:59'));

        static::assertSame(
            [$old?->id],
            array_map(static fn(Job $job): int => $job->id, $this->store->findStale(self::at('08:30'), 10)),
        );
        unset($edge);
    }

    #[Test]
    public function findStaleListsTheOldestLocksFirstUpToTheLimit(): void
    {
        $ids = [$this->push(due: '07:00'), $this->push(due: '07:00'), $this->push(due: '07:00')];
        $this->store->claimNext([], 'a', self::at('08:20'));
        $this->store->claimNext([], 'b', self::at('08:10'));
        $this->store->claimNext([], 'c', self::at('08:10'));

        static::assertSame(
            [$ids[1], $ids[2]],
            array_map(static fn(Job $job): int => $job->id, $this->store->findStale(self::at('09:00'), 2)),
        );
    }

    #[Test]
    public function findStaleSkipsAFinishedJobThatKeptItsLock(): void
    {
        $job = $this->claimed();
        $this->adapter->query(
            "UPDATE queue_job SET state = 'done' WHERE id = {$job->id}",
            Adapter::QUERY_MODE_EXECUTE,
        );

        static::assertSame([], $this->store->findStale(self::at('10:00'), 10));
    }

    #[Test]
    public function findStaleSkipsJobsThatAreNotRunning(): void
    {
        $this->push();
        $job = $this->claimed();
        $this->store->fail($job, 'x', self::at('09:00'));

        static::assertSame([], $this->store->findStale(self::at('10:00'), 10));
    }

    #[Test]
    public function finishingIsRefusedOnceTheJobIsNoLongerRunning(): void
    {
        $job = $this->claimed();
        $this->store->complete($job, self::at('09:01'));

        static::assertSame(
            [false, JobState::Done],
            [$this->store->fail($job, 'x', self::at('09:02')), $this->store->find($job->id)?->state],
        );
    }

    #[Test]
    public function finishingIsRefusedOnceTheLockHasChanged(): void
    {
        $job = $this->claimed();
        $this->store->retry($job, self::at('09:00'), 'Stale', self::at('09:00'));
        $this->store->claimNext([], 'worker-2', self::at('09:00'));

        static::assertSame(
            [false, false, false, 'worker-2'],
            [
                $this->store->complete($job, self::at('09:05')),
                $this->store->fail($job, 'x', self::at('09:05')),
                $this->store->retry($job, self::at('09:05'), 'x', self::at('09:05')),
                $this->store->find($job->id)?->lockedBy,
            ],
        );
    }

    #[Test]
    public function finishingOneJobLeavesTheWorkersOtherJobsRunning(): void
    {
        $this->push();
        $this->push();
        $first  = $this->store->claimNext([], 'worker-1', self::at('09:00'));
        $second = $this->store->claimNext([], 'worker-1', self::at('09:00'));
        self::assertNotNull($first);

        $this->store->complete($first, self::at('09:01'));

        static::assertSame(JobState::Running, $this->store->find((int) $second?->id)?->state);
    }

    #[Test]
    public function oldestWaitingSinceIsNullWhenNothingWaits(): void
    {
        $this->claimed();

        static::assertNull($this->store->oldestWaitingSince());
    }

    #[Test]
    public function oldestWaitingSinceIsWhenTheLongestWaitingJobWasPushed(): void
    {
        $this->store->push('default', 'j', '[]', self::at('09:00'), self::at('07:00'));
        $this->store->push('default', 'j', '[]', self::at('08:59'), self::at('06:00'));
        $this->store->push('default', 'j', '[]', self::at('09:00'), self::at('08:00'));
        $this->store->claimNext([], 'w', self::at('09:00'));

        static::assertSame('07:00:00', $this->store->oldestWaitingSince()?->format('H:i:s'));
    }

    #[Test]
    public function payloadSurvivesTheRoundTrip(): void
    {
        $id = $this->store->push('default', 'J', '{"name":"Zoë","n":[1,2]}', self::at('09:00'), self::at('09:00'));

        static::assertSame(['name' => 'Zoë', 'n' => [1, 2]], $this->store->find($id)?->payload());
    }

    #[Test]
    public function pruneDoneDeletesOnlyDoneJobsFinishedBeforeTheCutOff(): void
    {
        $oldDone = $this->claimed();
        $this->store->complete($oldDone, self::at('08:00'));
        $edgeDone = $this->claimed();
        $this->store->complete($edgeDone, self::at('08:30'));
        $oldFailed = $this->claimed();
        $this->store->fail($oldFailed, 'x', self::at('08:00'));
        $waiting = $this->push();

        static::assertSame(
            [1, null, true, true, true],
            [
                $this->store->pruneDone(self::at('08:30')),
                $this->store->find($oldDone->id),
                null !== $this->store->find($edgeDone->id),
                null !== $this->store->find($oldFailed->id),
                null !== $this->store->find($waiting),
            ],
        );
    }

    #[Test]
    public function pushReturnsANewIdEachTime(): void
    {
        $first  = $this->push();
        $second = $this->push();

        static::assertSame([true, $first + 1], [$first > 0, $second]);
    }

    #[Test]
    public function pushStoresAWaitingJob(): void
    {
        $id = $this->store->push(
            'mail',
            'mail.send',
            '{"to":"a@example.com"}',
            self::at('09:05'),
            self::at('09:00'),
        );
        $job = $this->store->find($id);

        static::assertEquals(
            new Job(
                id: $id,
                queue: 'mail',
                jobClass: 'mail.send',
                encodedPayload: '{"to":"a@example.com"}',
                state: JobState::Waiting,
                attempts: 0,
                nextAttemptAt: self::at('09:05'),
                lockedBy: null,
                lockedAt: null,
                lastError: null,
                createdAt: self::at('09:00'),
                updatedAt: self::at('09:00'),
                finishedAt: null,
            ),
            $job,
        );
    }

    #[Test]
    public function retryFailedGivesAFailedJobFreshAttempts(): void
    {
        $job = $this->claimed();
        $this->store->fail($job, 'Boom', self::at('09:01'));

        $retried = $this->store->retryFailed($job->id, self::at('10:00'));
        $stored  = $this->store->find($job->id);

        static::assertSame(
            [true, JobState::Waiting, 0, '10:00:00', null, '10:00:00', 'Boom'],
            [
                $retried,
                $stored?->state,
                $stored?->attempts,
                $stored?->nextAttemptAt->format('H:i:s'),
                $stored?->finishedAt,
                $stored?->updatedAt->format('H:i:s'),
                $stored?->lastError,
            ],
        );
    }

    #[Test]
    public function retryFailedLeavesOtherJobsAlone(): void
    {
        $waiting = $this->push();
        $running = $this->claimed();
        $done    = $this->claimed();
        $this->store->complete($done, self::at('09:00'));

        static::assertSame(
            [false, false, false, false],
            [
                $this->store->retryFailed($waiting, self::at('10:00')),
                $this->store->retryFailed($running->id, self::at('10:00')),
                $this->store->retryFailed($done->id, self::at('10:00')),
                $this->store->retryFailed(999, self::at('10:00')),
            ],
        );
    }

    #[Test]
    public function retryFailedRetriesOnlyThatJob(): void
    {
        $first = $this->claimed();
        $this->store->fail($first, 'x', self::at('09:01'));
        $second = $this->claimed();
        $this->store->fail($second, 'x', self::at('09:01'));

        $this->store->retryFailed($first->id, self::at('10:00'));

        static::assertSame(JobState::Failed, $this->store->find($second->id)?->state);
    }

    #[Test]
    public function retryPutsTheJobBackToWait(): void
    {
        $job = $this->claimed();

        $retried = $this->store->retry($job, self::at('09:10'), 'Later', self::at('09:04'));
        $stored  = $this->store->find($job->id);

        static::assertSame(
            [true, JobState::Waiting, null, null, null, '09:04:00', 'Later', '09:10:00', 1],
            [
                ...$this->summary($retried, $job->id),
                $stored?->lastError,
                $stored?->nextAttemptAt->format('H:i:s'),
                $stored?->attempts,
            ],
        );
    }

    protected function setUp(): void
    {
        $this->setUpDatabase();
        $this->store = new PhpDbJobStore($this->adapter);
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }

    private function claimed(): Job
    {
        $this->push();
        $job = $this->store->claimNext([], 'worker-1', self::at('09:00'));
        self::assertNotNull($job);

        return $job;
    }

    private function push(string $queue = 'default', string $due = '09:00'): int
    {
        return $this->store->push($queue, 'test.job', '[]', self::at($due), self::at('08:00'));
    }

    /**
     * @return list<mixed>
     */
    private function summary(bool $result, int $id): array
    {
        $job = $this->store->find($id);

        return [
            $result,
            $job?->state,
            $job?->lockedBy,
            $job?->lockedAt,
            $job?->finishedAt?->format('H:i:s'),
            $job?->updatedAt->format('H:i:s'),
        ];
    }
}
