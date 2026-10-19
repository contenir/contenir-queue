<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Storage;

use Contenir\Queue\Schedule\ScheduleRun;
use Contenir\Queue\Schedule\ScheduleState;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Storage\ScheduleStoreInterface;
use DateTimeImmutable;
use Override;

use function array_filter;
use function array_reverse;
use function array_slice;
use function array_values;
use function count;

/**
 * Schedule states and runs in arrays, behaving as PhpDbScheduleStore does,
 * with each job's state read from a job store.
 */
final class InMemoryScheduleStore implements ScheduleStoreInterface
{
    /** @var array<string, ScheduleState> */
    public array $states = [];

    /** @var list<ScheduleRun> */
    public array $runs = [];

    public function __construct(
        private readonly JobStoreInterface $jobs,
    ) {}

    #[Override]
    public function advance(string $name, DateTimeImmutable $due, DateTimeImmutable $next, DateTimeImmutable $now): bool
    {
        $state = $this->states[$name] ?? null;
        if (null === $state || $state->nextRunAt->getTimestamp() !== $due->getTimestamp()) {
            return false;
        }

        $this->states[$name] = new ScheduleState($name, $next, $due, $state->lastJobId);

        return true;
    }

    #[Override]
    public function pruneRuns(DateTimeImmutable $before): int
    {
        $count      = count($this->runs);
        $this->runs = array_values(array_filter(
            $this->runs,
            static fn(ScheduleRun $run): bool => $run->recordedAt >= $before,
        ));

        return $count - count($this->runs);
    }

    #[Override]
    public function recordRun(string $name, DateTimeImmutable $due, ?int $jobId, DateTimeImmutable $now): void
    {
        $this->runs[] = new ScheduleRun(count($this->runs) + 1, $name, $due, $now, $jobId, null);
        $state        = $this->states[$name];
        if (null !== $jobId) {
            $this->states[$name] = new ScheduleState($name, $state->nextRunAt, $state->lastRunAt, $jobId);
        }
    }

    #[Override]
    public function runs(string $name, int $limit): array
    {
        return array_slice(
            array_reverse(array_values(array_filter(
                $this->runs,
                static fn(ScheduleRun $run): bool => $run->schedule === $name,
            ))),
            offset: 0,
            length: $limit,
        );
    }

    #[Override]
    public function start(string $name, DateTimeImmutable $nextRunAt, DateTimeImmutable $now): bool
    {
        if (null !== ($this->states[$name] ?? null)) {
            return false;
        }

        $this->states[$name] = new ScheduleState($name, $nextRunAt);

        return true;
    }

    #[Override]
    public function states(): array
    {
        $states = [];
        foreach ($this->states as $name => $state) {
            $states[$name] = new ScheduleState(
                $name,
                $state->nextRunAt,
                $state->lastRunAt,
                $state->lastJobId,
                null === $state->lastJobId ? null : $this->jobs->find($state->lastJobId)?->state,
            );
        }

        return $states;
    }
}
