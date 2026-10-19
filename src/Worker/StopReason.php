<?php

declare(strict_types=1);

namespace Contenir\Queue\Worker;

/**
 * Why a worker run ended.
 *
 * @api
 */
enum StopReason: string
{
    /** Another worker holds the lock, so this one never started. */
    case AlreadyRunning = 'already running';

    /** Nothing was due, on a run with a time limit. */
    case QueueEmpty = 'queue empty';

    /** The time limit was reached. */
    case TimeUp = 'time up';

    /** The job limit was reached. */
    case MaxJobs = 'job limit';

    /** The process reached its memory limit. */
    case MemoryLimit = 'memory limit';

    /** {@see Worker::stop()} was called, by a signal handler for instance. */
    case Stopped = 'stopped';

    /** The lock ran out and another worker took it. */
    case LockLost = 'lock lost';
}
