<?php

declare(strict_types=1);

namespace Contenir\Queue\Worker;

/**
 * The process a worker runs in: how it waits, and how much memory it uses.
 *
 * @api
 */
interface ProcessInterface
{
    /**
     * Bytes of memory the process has, for the worker's memory limit.
     */
    public function memoryUsage(): int;

    public function sleep(int $seconds): void;
}
