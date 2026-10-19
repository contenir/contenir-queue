<?php

declare(strict_types=1);

namespace Contenir\Queue\Worker;

use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Storage\ScheduleStoreInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

use function count;

/**
 * Tidying a worker does before it takes jobs: retrying jobs whose worker died
 * mid-run, and deleting old done jobs and schedule runs.
 *
 * @api
 */
final readonly class Housekeeping
{
    public const string STALE_ERROR = 'The worker running this job stopped before it finished';

    /**
     * Most stale jobs recovered in one go; the rest wait for the next run.
     */
    public const int STALE_BATCH = 50;

    public function __construct(
        private JobStoreInterface $jobs,
        private ScheduleStoreInterface $schedules,
        private JobRunner $runner,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * Delete done jobs, and records of schedule runs, older than the given
     * number of days, unless it is 0. Returns how many jobs were deleted.
     *
     * @throws StorageException
     */
    public function pruneDone(int $days): int
    {
        if (0 === $days) {
            return 0;
        }

        $before = $this->clock->now()->modify("-{$days} days");
        $jobs   = $this->jobs->pruneDone($before);
        $runs   = $this->schedules->pruneRuns($before);
        if (($jobs + $runs) > 0) {
            $this->logger->info('Deleted {jobs} done jobs and {runs} schedule runs older than {days} days', [
                'jobs' => $jobs,
                'runs' => $runs,
                'days' => $days,
            ]);
        }

        return $jobs;
    }

    /**
     * Retry, or fail, running jobs locked longer ago than the given seconds.
     *
     * @throws StorageException
     */
    public function recoverStale(int $staleAfterSeconds): int
    {
        $stale = $this->jobs->findStale(
            $this->clock->now()->modify("-{$staleAfterSeconds} seconds"),
            self::STALE_BATCH,
        );
        foreach ($stale as $job) {
            $this->runner->recordFailure($job, self::STALE_ERROR);
        }

        return count($stale);
    }
}
