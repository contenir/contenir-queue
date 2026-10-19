<?php

declare(strict_types=1);

namespace Contenir\Queue\Worker;

use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Storage\JobStoreInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

use function count;

/**
 * Tidying a worker does before it takes jobs: retrying jobs whose worker died
 * mid-run, and deleting old done jobs.
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
        private JobRunner $runner,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * Delete done jobs older than the given number of days, unless it is 0.
     *
     * @throws StorageException
     */
    public function pruneDone(int $days): int
    {
        if (0 === $days) {
            return 0;
        }

        $pruned = $this->jobs->pruneDone($this->clock->now()->modify("-{$days} days"));
        if ($pruned > 0) {
            $this->logger->info('Deleted {count} done jobs older than {days} days', [
                'count' => $pruned,
                'days'  => $days,
            ]);
        }

        return $pruned;
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
