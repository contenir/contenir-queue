<?php

declare(strict_types=1);

namespace Contenir\Queue\Monitor;

/**
 * How many jobs are in each state.
 *
 * @api
 */
final readonly class JobCounts
{
    public function __construct(
        public int $waiting,
        public int $running,
        public int $done,
        public int $failed,
    ) {}
}
