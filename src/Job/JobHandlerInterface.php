<?php

declare(strict_types=1);

namespace Contenir\Queue\Job;

use Throwable;

/**
 * Runs one kind of job. A job names its handler by class, and the worker gets
 * the handler from the PSR-11 container under that name.
 *
 * Returning means the job is done. Throwing {@see RetryLaterException} asks
 * for another attempt at a given time (an HTTP Retry-After, say), throwing
 * {@see UnrecoverableJobException} fails the job at once, and any other
 * exception retries it with back-off until the attempts run out.
 *
 * @api
 */
interface JobHandlerInterface
{
    /**
     * @throws Throwable
     */
    public function handle(Job $job): void;
}
