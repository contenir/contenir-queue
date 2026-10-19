<?php

declare(strict_types=1);

namespace Contenir\Queue\Job;

/**
 * Where a job is in its life: waiting to run (first time or a retry), running
 * under a worker's lock, done, or failed after its last attempt.
 */
enum JobState: string
{
    case Waiting = 'waiting';
    case Running = 'running';
    case Done    = 'done';
    case Failed  = 'failed';
}
