<?php

declare(strict_types=1);

namespace Contenir\Queue\Job;

/**
 * What happened to a job a worker ran: done, waiting for another attempt,
 * failed for good, or taken from this worker before it finished (another
 * process recovered it as stale).
 */
enum JobOutcome: string
{
    case Done     = 'done';
    case Retrying = 'retrying';
    case Failed   = 'failed';
    case Lost     = 'lost';
}
