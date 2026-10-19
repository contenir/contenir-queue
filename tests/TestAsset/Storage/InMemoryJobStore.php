<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Storage;

use Contenir\Queue\Job\Job;
use Contenir\Queue\Job\JobState;
use Contenir\Queue\Storage\JobStoreInterface;
use DateTimeImmutable;
use Override;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function usort;

/**
 * Jobs in an array, behaving as PhpDbJobStore does, for unit tests of the
 * code that drives jobs.
 */
final class InMemoryJobStore implements JobStoreInterface
{
    /** @var array<int, Job> */
    public array $jobs = [];

    private int $nextId = 1;

    /**
     * @param array<string, mixed> $changes
     */
    private static function with(Job $job, array $changes): Job
    {
        $values = [
            'id'             => $job->id,
            'queue'          => $job->queue,
            'jobClass'       => $job->jobClass,
            'encodedPayload' => $job->encodedPayload,
            'state'          => $job->state,
            'attempts'       => $job->attempts,
            'nextAttemptAt'  => $job->nextAttemptAt,
            'lockedBy'       => $job->lockedBy,
            'lockedAt'       => $job->lockedAt,
            'lastError'      => $job->lastError,
            'createdAt'      => $job->createdAt,
            'updatedAt'      => $job->updatedAt,
            'finishedAt'     => $job->finishedAt,
            ...$changes,
        ];

        return new Job(...$values);
    }

    #[Override]
    public function claimNext(array $queues, string $lockedBy, DateTimeImmutable $now): ?Job
    {
        $due = array_filter(
            $this->jobs,
            static fn(Job $job): bool => (
                JobState::Waiting === $job->state
                && $job->nextAttemptAt <= $now
                && ([] === $queues || in_array($job->queue, $queues, strict: true))
            ),
        );
        usort($due, static fn(Job $a, Job $b): int => [$a->nextAttemptAt, $a->id] <=> [$b->nextAttemptAt, $b->id]);
        $job = $due[0] ?? null;
        if (null === $job) {
            return null;
        }

        return $this->jobs[$job->id] = self::with($job, [
            'state'     => JobState::Running,
            'attempts'  => $job->attempts + 1,
            'lockedBy'  => $lockedBy,
            'lockedAt'  => $now,
            'updatedAt' => $now,
        ]);
    }

    #[Override]
    public function complete(Job $job, DateTimeImmutable $now): bool
    {
        return $this->finish($job, ['state' => JobState::Done, 'finishedAt' => $now], $now);
    }

    #[Override]
    public function fail(Job $job, string $error, DateTimeImmutable $now): bool
    {
        return $this->finish($job, ['state' => JobState::Failed, 'lastError' => $error, 'finishedAt' => $now], $now);
    }

    #[Override]
    public function find(int $id): ?Job
    {
        return $this->jobs[$id] ?? null;
    }

    #[Override]
    public function findStale(DateTimeImmutable $lockedBefore, int $limit): array
    {
        return array_slice(
            array_values(array_filter(
                $this->jobs,
                static fn(Job $job): bool => JobState::Running === $job->state && $job->lockedAt < $lockedBefore,
            )),
            offset: 0,
            length: $limit,
        );
    }

    #[Override]
    public function pruneDone(DateTimeImmutable $finishedBefore): int
    {
        $before     = count($this->jobs);
        $this->jobs = array_filter(
            $this->jobs,
            static fn(Job $job): bool => ! (JobState::Done === $job->state && $job->finishedAt < $finishedBefore),
        );

        return $before - count($this->jobs);
    }

    #[Override]
    public function push(
        string $queue,
        string $jobClass,
        string $encodedPayload,
        DateTimeImmutable $nextAttemptAt,
        DateTimeImmutable $now,
    ): int {
        $id              = $this->nextId++;
        $this->jobs[$id] = new Job(
            id: $id,
            queue: $queue,
            jobClass: $jobClass,
            encodedPayload: $encodedPayload,
            state: JobState::Waiting,
            attempts: 0,
            nextAttemptAt: $nextAttemptAt,
            lockedBy: null,
            lockedAt: null,
            lastError: null,
            createdAt: $now,
            updatedAt: $now,
            finishedAt: null,
        );

        return $id;
    }

    #[Override]
    public function retry(Job $job, DateTimeImmutable $nextAttemptAt, string $error, DateTimeImmutable $now): bool
    {
        return $this->finish(
            $job,
            ['state' => JobState::Waiting, 'nextAttemptAt' => $nextAttemptAt, 'lastError' => $error],
            $now,
        );
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function finish(Job $job, array $changes, DateTimeImmutable $now): bool
    {
        $stored = $this->jobs[$job->id] ?? null;
        if (null === $stored || JobState::Running !== $stored->state || $stored->lockedBy !== $job->lockedBy) {
            return false;
        }

        $this->jobs[$job->id] = self::with($stored, [
            ...$changes,
            'lockedBy'  => null,
            'lockedAt'  => null,
            'updatedAt' => $now,
        ]);

        return true;
    }
}
