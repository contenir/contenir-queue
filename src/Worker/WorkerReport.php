<?php

declare(strict_types=1);

namespace Contenir\Queue\Worker;

/**
 * What one worker run did.
 *
 * @api
 */
final readonly class WorkerReport
{
    public function __construct(
        public StopReason $reason,
        public int $jobsRun = 0,
        public int $recovered = 0,
        public int $pruned = 0,
    ) {}
}
