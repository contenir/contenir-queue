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
    public function findReturnsNullForAnUnknownJob(): void
    {
        static::assertNull($this->store->find(999));
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
    public function payloadSurvivesTheRoundTrip(): void
    {
        $id = $this->store->push('default', 'J', '{"name":"Zoë","n":[1,2]}', self::at('09:00'), self::at('09:00'));

        static::assertSame(['name' => 'Zoë', 'n' => [1, 2]], $this->store->find($id)?->payload());
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
